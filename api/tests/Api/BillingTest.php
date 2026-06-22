<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Billing\InMemoryStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Billing endpoints (#billing): checkout/portal/status access gating and the
 * webhook → Subscription sync. Stripe is the in-memory fake
 * ({@see InMemoryStripeGateway}), so nothing leaves the box; the fake records
 * the Checkout/Portal calls and accepts a fixed webhook signature.
 */
class BillingTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Subscription')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCheckoutRequiresAuth(): void
    {
        static::createClient()->request('POST', '/spaces/' . str_repeat('0', 36) . '/billing/checkout', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCheckoutHidesSpaceFromNonMembers(): void
    {
        $alice = $this->createUser('alice@example.com');
        $mallory = $this->createUser('mallory@example.com');
        $space = $this->createSpace($alice, 'Hidden');

        $client = static::createClient();
        $client->loginUser($mallory);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/checkout', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCheckoutForbiddenForNonAdminMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/checkout', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminGetsCheckoutUrl(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/checkout', [
            'json' => ['interval' => 'year'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['url' => InMemoryStripeGateway::CHECKOUT_URL]);

        // phpstan-symfony infers InMemoryStripeGateway from the class-string.
        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        $this->assertCount(1, $gateway->checkoutSessions);
        $session = $gateway->checkoutSessions[0];
        $this->assertSame('price_test_yearly', $session['priceId']);
        $this->assertSame((string) $space->getId(), $session['metadata']['space_id']);
    }

    public function testCheckoutRejectsPersonalSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $personal = $this->createSpace($alice, 'Private', personal: true);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $personal->getId() . '/billing/checkout', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPortalRequiresExistingCustomer(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/portal', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testPortalReturnsUrlForSubscribedSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_portal');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/portal', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['url' => InMemoryStripeGateway::PORTAL_URL]);

        // phpstan-symfony infers InMemoryStripeGateway from the class-string.
        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        $this->assertCount(1, $gateway->portalSessions);
        $this->assertSame('cus_portal', $gateway->portalSessions[0]['customerId']);
    }

    public function testStatusReportsFree(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/spaces/' . $space->getId() . '/billing');
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['plan' => 'free', 'active' => false]);
    }

    public function testStatusReportsActiveWhenSubscribed(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');
        // Seed before the request — entities created after a request are
        // detached once the kernel reboots between requests.
        $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_x', 'sub_status');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/spaces/' . $space->getId() . '/billing');
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['plan' => 'team', 'active' => true, 'status' => 'active']);
    }

    public function testWebhookRejectsBadSignature(): void
    {
        $client = static::createClient();
        $client->request('POST', '/billing/webhook', [
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => 'nope'],
            'body' => '{"type":"customer.subscription.created","data":{"object":{}}}',
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testWebhookCreatesSubscription(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');

        static::createClient()->request('POST', '/billing/webhook', [
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => InMemoryStripeGateway::VALID_SIGNATURE],
            'body' => $this->subscriptionEvent('customer.subscription.created', 'sub_abc', 'active', (string) $space->getId()),
        ]);
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $row = $this->entityManager->getRepository(Subscription::class)->findOneBy(['stripeSubscriptionId' => 'sub_abc']);
        $this->assertInstanceOf(Subscription::class, $row);
        $this->assertSame(Subscription::STATUS_ACTIVE, $row->getStatus());
        $this->assertSame('cus_evt', $row->getStripeCustomerId());
        $this->assertSame(3, $row->getSeats());
        $this->assertSame('month', $row->getBillingInterval());
    }

    public function testWebhookUpdateIsIdempotent(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');
        // Seed the existing row before the request (cross-request writes
        // aren't visible to a later request once the kernel reboots).
        $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_evt', 'sub_abc');

        static::createClient()->request('POST', '/billing/webhook', [
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => InMemoryStripeGateway::VALID_SIGNATURE],
            'body' => $this->subscriptionEvent('customer.subscription.updated', 'sub_abc', 'past_due', (string) $space->getId()),
        ]);
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $this->assertCount(1, $this->entityManager->getRepository(Subscription::class)->findAll());
        $row = $this->entityManager->getRepository(Subscription::class)->findOneBy(['stripeSubscriptionId' => 'sub_abc']);
        $this->assertInstanceOf(Subscription::class, $row);
        $this->assertSame(Subscription::STATUS_PAST_DUE, $row->getStatus());
    }

    public function testWebhookDeleteCancels(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_evt', 'sub_abc');

        static::createClient()->request('POST', '/billing/webhook', [
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => InMemoryStripeGateway::VALID_SIGNATURE],
            // Payload says active, but a delete event forces canceled.
            'body' => $this->subscriptionEvent('customer.subscription.deleted', 'sub_abc', 'active', (string) $space->getId()),
        ]);
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $row = $this->entityManager->getRepository(Subscription::class)->findOneBy(['stripeSubscriptionId' => 'sub_abc']);
        $this->assertInstanceOf(Subscription::class, $row);
        $this->assertSame(Subscription::STATUS_CANCELED, $row->getStatus());
    }

    private function subscriptionEvent(string $type, string $subId, string $status, string $spaceId): string
    {
        return json_encode([
            'type' => $type,
            'data' => [
                'object' => [
                    'id' => $subId,
                    'status' => $status,
                    'customer' => 'cus_evt',
                    'cancel_at_period_end' => false,
                    'current_period_end' => 1999999999,
                    'metadata' => ['space_id' => $spaceId],
                    'items' => [
                        'data' => [
                            [
                                'quantity' => 3,
                                'price' => ['id' => 'price_test_monthly', 'recurring' => ['interval' => 'month']],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function seedSubscription(
        Space $space,
        string $status,
        string $customerId,
        ?string $stripeSubscriptionId = null,
    ): Subscription {
        $subscription = (new Subscription())
            ->setSpace($space)
            ->setStatus($status)
            ->setStripeCustomerId($customerId)
            ->setStripeSubscriptionId($stripeSubscriptionId ?? 'sub_' . bin2hex(random_bytes(6)));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private function createSpace(User $owner, string $name, bool $personal = false): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($owner);
        if ($personal) {
            $space->setIsPersonal(true);
            $space->setVisibility(Space::VISIBILITY_PRIVATE);
        }
        $this->entityManager->persist($space);

        $admin = (new SpaceMembership())->setUser($owner)->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($admin);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $space;
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
