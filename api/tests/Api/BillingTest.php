<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\CancellationFeedback;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Service\AccountDeletionService;
use App\Service\PersonalOrganizationProvisioner;
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

        $this->entityManager->createQuery('DELETE FROM App\Entity\CancellationFeedback')->execute();
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
        // No plan specified → defaults to Pro; yearly interval.
        $this->assertSame('price_test_pro_yearly', $session['priceId']);
        $this->assertSame((string) $space->getId(), $session['metadata']['space_id']);
        $this->assertSame('pro', $session['metadata']['plan']);
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

    public function testCancelRequiresActiveSubscription(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/cancel', [
            'json' => ['reason' => 'too_expensive'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testCancelRequiresReason(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_cancel', 'sub_cancel');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/cancel', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // Stripe was never called, and no feedback was recorded.
        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        $this->assertCount(0, $gateway->canceledSubscriptions);
    }

    public function testCancelForbiddenForNonAdminMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);
        $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_cancel', 'sub_cancel');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/cancel', [
            'json' => ['reason' => 'too_expensive'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCancelSchedulesAtPeriodEndAndRecordsFeedback(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_cancel', 'sub_cancel');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/cancel', [
            'json' => ['reason' => 'too_expensive', 'comment' => 'Tightening the budget.'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['ok' => true, 'cancelAtPeriodEnd' => true]);

        // Stripe was asked to cancel the right subscription at period end.
        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        $this->assertSame(['sub_cancel'], $gateway->canceledSubscriptions);

        $this->entityManager->clear();
        $row = $this->entityManager->getRepository(Subscription::class)->findOneBy(['stripeSubscriptionId' => 'sub_cancel']);
        $this->assertInstanceOf(Subscription::class, $row);
        $this->assertTrue($row->getCancelAtPeriodEnd());

        $feedback = $this->entityManager->getRepository(CancellationFeedback::class)->findOneBy([]);
        $this->assertNotNull($feedback);
        $this->assertSame(CancellationFeedback::CONTEXT_SUBSCRIPTION_CANCELLATION, $feedback->getContext());
        $this->assertSame('too_expensive', $feedback->getReason());
        $this->assertSame('Tightening the budget.', $feedback->getComment());
        $this->assertSame((string) $space->getId(), (string) $feedback->getSpace()?->getId());
    }

    public function testCancelRejectsAlreadyCanceling(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $sub = $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_cancel', 'sub_cancel');
        $sub->setCancelAtPeriodEnd(true);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/billing/cancel', [
            'json' => ['reason' => 'too_expensive'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
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

    /**
     * #stripe-mode: the sandbox-vs-live flag is derived from the key prefix and
     * ridden along on every billing status payload, so the UI can badge a test
     * instance without a second round-trip.
     */
    public function testStatusReportsStripeMode(): void
    {
        $alice = $this->createUser('alice-mode@example.com');
        $space = $this->createSpace($alice, 'Shared');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/spaces/' . $space->getId() . '/billing');
        $this->assertResponseStatusCodeSame(200);
        // The suite's fake gateway runs in test mode, like a sandbox instance.
        // The prefix-derivation itself is unit-tested against the real gateway
        // in {@see \App\Tests\Billing\StripeGatewayTest}.
        $this->assertJsonContains(['testMode' => true]);
    }

    /**
     * The same flag reaches the admin chrome via /api/me, so a sandbox
     * instance can be badged without a dedicated fetch. Admin-only: it's
     * instance configuration, not something to hand every session.
     */
    public function testApiMeExposesStripeModeToAdminsOnly(): void
    {
        $admin = $this->createUser('admin-mode@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $plain = $this->createUser('plain-mode@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $body = $client->request('GET', '/api/me')->toArray();
        $platform = $body['platform'] ?? null;
        $this->assertIsArray($platform);
        $this->assertTrue($platform['stripeTestMode'] ?? false);

        $client->loginUser($plain);
        $body = $client->request('GET', '/api/me')->toArray();
        $this->assertArrayHasKey('platform', $body);
        $this->assertNull($body['platform']);
    }

    public function testStatusWithGroupInTheSpace(): void
    {
        // Repro for the prod billing 500: getEffectiveUsers() walks the space's
        // groups (#groups-space), so a space that owns a group must still
        // return a clean status.
        $alice = $this->createUser('alice-grp@example.com');
        $bob = $this->createUser('bob-grp@example.com');
        $space = $this->createSpace($alice, 'Shared with a group');

        $group = (new UserGroup())->setSpace($space)->setTitle('Crew')->setSlug('crew-billing');
        $group->addMember($alice);
        $group->addMember($bob);
        $this->entityManager->persist($group);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/spaces/' . $space->getId() . '/billing');
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['limits' => ['memberCount' => 2]]);
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
        // Legacy 'team' resolves to the Business tier in the entitlement catalog.
        $this->assertJsonContains(['plan' => 'business', 'active' => true, 'status' => 'active']);
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

    public function testBillingStatusReturnsFreePlanEntitlements(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');

        $client = static::createClient();
        $client->loginUser($alice);
        $body = $client->request('GET', '/spaces/' . $space->getId() . '/billing')->toArray();

        $this->assertSame('free', $body['plan']);
        $features = $body['features'];
        $this->assertIsArray($features);
        $this->assertFalse($features['calendar_sync']);
        $this->assertFalse($features['automations']);
        $this->assertTrue($features['time_tracking']);
        $limits = $body['limits'];
        $this->assertIsArray($limits);
        $this->assertSame(5, $limits['space_members']);
    }

    public function testBillingStatusReflectsProPlan(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $subscription = $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_pro');
        $subscription->setPlan('pro');
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $body = $client->request('GET', '/spaces/' . $space->getId() . '/billing')->toArray();

        $this->assertSame('pro', $body['plan']);
        $features = $body['features'];
        $this->assertIsArray($features);
        $this->assertTrue($features['calendar_sync']);
        $this->assertFalse($features['automations']);
        $limits = $body['limits'];
        $this->assertIsArray($limits);
        $this->assertNull($limits['space_members'], 'Pro lifts the member cap');
    }

    public function testBillingStatusReflectsBusinessPlanAndLegacyTeamAlias(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');
        // Default plan is the legacy 'team' → resolves to Business.
        $this->seedSubscription($space, Subscription::STATUS_ACTIVE, 'cus_biz');

        $client = static::createClient();
        $client->loginUser($alice);
        $body = $client->request('GET', '/spaces/' . $space->getId() . '/billing')->toArray();

        $this->assertSame('business', $body['plan']);
        $features = $body['features'];
        $this->assertIsArray($features);
        $this->assertTrue($features['automations']);
        $this->assertTrue($features['sso']);
        $this->assertTrue($features['ai_assist']);
        $this->assertFalse($features['scim'], 'SCIM is Enterprise-only');
    }

    public function testInvoicePaymentSucceededEmailsReceipt(): void
    {
        static::createClient()->request('POST', '/billing/webhook', [
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => InMemoryStripeGateway::VALID_SIGNATURE],
            'body' => json_encode([
                'type' => 'invoice.payment_succeeded',
                'data' => ['object' => [
                    'subscription' => 'sub_receipt',
                    'amount_paid' => 1200,
                    'currency' => 'usd',
                    'number' => 'INV-0001',
                    'customer_email' => 'payer@example.com',
                    'hosted_invoice_url' => 'https://invoice.stripe.test/i/1',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        $this->assertEmailAddressContains($email, 'To', 'payer@example.com');
        $this->assertEmailHeaderSame($email, 'Subject', 'Your Madori payment receipt');
    }

    public function testInvoicePaymentSucceededSkipsNonSubscriptionInvoice(): void
    {
        static::createClient()->request('POST', '/billing/webhook', [
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => InMemoryStripeGateway::VALID_SIGNATURE],
            'body' => json_encode([
                'type' => 'invoice.payment_succeeded',
                'data' => ['object' => ['amount_paid' => 500, 'currency' => 'usd', 'customer_email' => 'x@example.com']],
            ], JSON_THROW_ON_ERROR),
        ]);

        // No `subscription` on the invoice → a one-off payment, not our receipt.
        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(0);
    }

    public function testAccountDeletionCancelsPersonalStripeSubscription(): void
    {
        $user = $this->createUser('leaver@example.com');
        $subscription = (new Subscription())
            ->setOrganization($this->personalOrgOf($user))
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeCustomerId('cus_leaver')
            ->setStripeSubscriptionId('sub_leaver');
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        // phpstan-symfony infers the concrete class from the class-string.
        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        $service = static::getContainer()->get(AccountDeletionService::class);
        $service->deleteAccount($user);

        $this->assertContains('sub_leaver', $gateway->immediatelyCanceledSubscriptions);

        $this->entityManager->clear();
        $this->assertNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'leaver@example.com']));
    }

    private function seedSubscription(
        Space $space,
        string $status,
        string $customerId,
        ?string $stripeSubscriptionId = null,
    ): Subscription {
        // Subscriptions belong to organizations now, so seed against the one
        // that owns the space (the default listener attached it on persist).
        $subscription = (new Subscription())
            ->setOrganization($space->getOrganization())
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

    /**
     * The user's personal organization, provisioning it if this test built the
     * user by direct persistence (which skips the signup provisioner).
     */
    private function personalOrgOf(User $user): Organization
    {
        $provisioner = static::getContainer()->get(PersonalOrganizationProvisioner::class);
        $org = $provisioner->provision($user);
        $this->entityManager->flush();

        return $org;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
