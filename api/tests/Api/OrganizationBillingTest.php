<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Billing\InMemoryStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Organization-account billing (#billing Phase 1b): plan status, Business
 * checkout, and the webhook that creates an org-owned subscription.
 */
class OrganizationBillingTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Subscription')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\OrganizationMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Organization')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testStatusFreeForNewOrg(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice);

        $client = static::createClient();
        $client->loginUser($alice);
        $body = $client->request('GET', '/organizations/' . $org->getId() . '/billing')->toArray();

        $this->assertSame('free', $body['plan']);
        $features = $body['features'];
        $this->assertIsArray($features);
        $this->assertFalse($features['automations']);
        $this->assertSame(1, $body['seatCount']);
    }

    public function testStatusBusinessWithSubscription(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice);
        $this->seedOrgSubscription($org, 'business');

        $client = static::createClient();
        $client->loginUser($alice);
        $body = $client->request('GET', '/organizations/' . $org->getId() . '/billing')->toArray();

        $this->assertSame('business', $body['plan']);
        $features = $body['features'];
        $this->assertIsArray($features);
        $this->assertTrue($features['automations']);
        $this->assertTrue($features['sso']);
    }

    public function testCheckoutReturnsUrlAndStampsOrg(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/organizations/' . $org->getId() . '/billing/checkout', [
            'json' => ['interval' => 'year'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['url' => InMemoryStripeGateway::CHECKOUT_URL]);

        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        $this->assertCount(1, $gateway->checkoutSessions);
        $session = $gateway->checkoutSessions[0];
        $this->assertSame('price_test_biz_yearly', $session['priceId']);
        $this->assertSame(1, $session['quantity']);
        $this->assertSame((string) $org->getId(), $session['metadata']['organization_id']);
        $this->assertSame('business', $session['metadata']['plan']);
    }

    public function testCheckoutForbiddenForNonAdmin(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice);
        $bob = $this->createUser('bob@example.com');
        $org->addMember($bob, Organization::ROLE_MEMBER);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/organizations/' . $org->getId() . '/billing/checkout', [
            'json' => ['interval' => 'month'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testWebhookCreatesOrgSubscription(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice);

        static::createClient()->request('POST', '/billing/webhook', [
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => InMemoryStripeGateway::VALID_SIGNATURE],
            'body' => $this->orgEvent('sub_org', 'active', (string) $org->getId()),
        ]);
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $row = $this->entityManager->getRepository(Subscription::class)->findOneBy(['stripeSubscriptionId' => 'sub_org']);
        $this->assertInstanceOf(Subscription::class, $row);
        $rowOrg = $row->getOrganization();
        $this->assertNotNull($rowOrg);
        $this->assertSame((string) $org->getId(), (string) $rowOrg->getId());
        $this->assertSame('business', $row->getPlan());
    }

    private function orgEvent(string $subId, string $status, string $orgId): string
    {
        return json_encode([
            'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => $subId,
                'status' => $status,
                'customer' => 'cus_org',
                'cancel_at_period_end' => false,
                'current_period_end' => 1999999999,
                'metadata' => ['organization_id' => $orgId, 'plan' => 'business'],
                'items' => ['data' => [[
                    'quantity' => 1,
                    'price' => ['id' => 'price_test_biz_monthly', 'recurring' => ['interval' => 'month']],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function seedOrgSubscription(Organization $org, string $plan): void
    {
        $subscription = (new Subscription())
            ->setOrganization($org)
            ->setPlan($plan)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeCustomerId('cus_x')
            ->setStripeSubscriptionId('sub_' . bin2hex(random_bytes(4)));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }

    private function makeOrg(User $owner): Organization
    {
        $org = (new Organization())->setName('Acme')->setSlug('o-' . bin2hex(random_bytes(4)))->setCreatedBy($owner);
        $org->addMember($owner, Organization::ROLE_OWNER);
        $this->entityManager->persist($org);
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
