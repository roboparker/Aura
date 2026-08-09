<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\PersonalOrganizationProvisioner;
use App\Tests\Billing\InMemoryStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Personal-account billing (#billing Phase 1b): the current user's own Free/Pro
 * plan via /me/billing, and the webhook that creates a user-owned subscription.
 */
class MeBillingTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Subscription')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testStatusFree(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('solo@example.com'));
        $body = $client->request('GET', '/me/billing')->toArray();

        $this->assertSame('free', $body['plan']);
        $features = $body['features'];
        $this->assertIsArray($features);
        $this->assertFalse($features['calendar_sync']);
    }

    public function testStatusPro(): void
    {
        $user = $this->createUser('pro@example.com');
        $this->seedPersonalSubscription($user, 'pro');

        $client = static::createClient();
        $client->loginUser($user);
        $body = $client->request('GET', '/me/billing')->toArray();

        $this->assertSame('pro', $body['plan']);
        $features = $body['features'];
        $this->assertIsArray($features);
        $this->assertTrue($features['calendar_sync']);
        $this->assertFalse($features['automations'], 'automations is a Business feature');
    }

    public function testCheckoutStampsUser(): void
    {
        $user = $this->createUser('buy@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/me/billing/checkout', [
            'json' => ['interval' => 'month'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['url' => InMemoryStripeGateway::CHECKOUT_URL]);

        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        $this->assertCount(1, $gateway->checkoutSessions);
        $session = $gateway->checkoutSessions[0];
        $this->assertSame('price_test_pro_monthly', $session['priceId']);
        $this->assertSame(1, $session['quantity']);
        $this->assertSame((string) $user->getId(), $session['metadata']['user_id']);
        $this->assertSame('pro', $session['metadata']['plan']);
    }

    public function testWebhookCreatesPersonalSubscription(): void
    {
        $user = $this->createUser('wh@example.com');

        static::createClient()->request('POST', '/billing/webhook', [
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => InMemoryStripeGateway::VALID_SIGNATURE],
            'body' => $this->personalEvent('sub_me', (string) $user->getId()),
        ]);
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $row = $this->entityManager->getRepository(Subscription::class)->findOneBy(['stripeSubscriptionId' => 'sub_me']);
        $this->assertInstanceOf(Subscription::class, $row);
        // A personal plan lands on the buyer's personal organization — their
        // account — rather than a row pointed at the user.
        $organization = $row->getOrganization();
        $this->assertNotNull($organization);
        $this->assertTrue($organization->getIsPersonal());
        $this->assertSame((string) $user->getId(), (string) $organization->getCreatedBy()?->getId());
        $this->assertSame('pro', $row->getPlan());
    }

    private function personalEvent(string $subId, string $userId): string
    {
        return json_encode([
            'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => $subId,
                'status' => 'active',
                'customer' => 'cus_me',
                'cancel_at_period_end' => false,
                'current_period_end' => 1999999999,
                'metadata' => ['user_id' => $userId, 'plan' => 'pro'],
                'items' => ['data' => [[
                    'quantity' => 1,
                    'price' => ['id' => 'price_test_pro_monthly', 'recurring' => ['interval' => 'month']],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function seedPersonalSubscription(User $user, string $plan): void
    {
        $subscription = (new Subscription())
            ->setOrganization($this->personalOrgOf($user))
            ->setPlan($plan)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeCustomerId('cus_p')
            ->setStripeSubscriptionId('sub_' . bin2hex(random_bytes(4)));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
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
