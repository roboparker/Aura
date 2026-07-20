<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Tests\Billing\InMemoryStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Stripe Connect onboarding + payment routing (#connect): a space owner sets up
 * where client invoice payments land, and once ready the public pay checkout is
 * routed to their connected account instead of the platform.
 */
class ConnectTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Invoice')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testOnboardCreatesAccountAndReturnsOnboardingUrl(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceId = (string) $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);

        // Before onboarding: no account.
        $status = $client->request('GET', '/spaces/' . $spaceId . '/connect/status')->toArray();
        $this->assertSame('none', $status['status'] ?? null);

        // Onboard: creates an account + returns a hosted onboarding URL.
        $onboard = $client->request('POST', '/spaces/' . $spaceId . '/connect/onboard', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(InMemoryStripeGateway::ONBOARD_URL, $onboard['url'] ?? null);

        // The account id is now stamped on the space, but onboarding isn't done.
        $this->assertNotNull($this->accountIdFor($spaceId));
        $status = $client->request('GET', '/spaces/' . $spaceId . '/connect/status')->toArray();
        $this->assertSame('incomplete', $status['status'] ?? null);
    }

    public function testOnboardRequiresAdmin(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceId = (string) $space->getId();

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('POST', '/spaces/' . $spaceId . '/connect/onboard', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAccountUpdatedWebhookEnablesPaymentAndRoutesToConnectedAccount(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();
        $spaceId = (string) $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'lineItems' => [['description' => 'Work', 'quantity' => 1, 'unitAmount' => 5000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $invoiceIri = $invoice['@id'];
        $this->assertIsString($invoiceIri);
        $sent = $client->request('POST', $invoiceIri . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $token = $sent['token'];
        $this->assertIsString($token);

        // No Connect account yet → online pay is blocked.
        $client->request('POST', '/public/invoices/' . $token . '/pay', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(503);

        // Onboard, then Stripe reports the account ready via the account.updated
        // webhook (the reliable readiness path).
        $client->request('POST', '/spaces/' . $spaceId . '/connect/onboard', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $accountId = $this->accountIdFor($spaceId);
        $this->assertIsString($accountId);

        $client->request('POST', '/billing/webhook', [
            'body' => json_encode([
                'type' => 'account.updated',
                'data' => ['object' => [
                    'id' => $accountId,
                    'charges_enabled' => true,
                    'details_submitted' => true,
                ]],
            ]),
            'headers' => [
                'Stripe-Signature' => InMemoryStripeGateway::VALID_SIGNATURE,
                'Content-Type' => 'application/json',
            ],
        ]);
        $this->assertResponseIsSuccessful();

        // Now pay succeeds and routes the charge to the connected account.
        $pay = $client->request('POST', '/public/invoices/' . $token . '/pay', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(InMemoryStripeGateway::PAYMENT_URL, $pay['url'] ?? null);

        // The checkout session created during the pay request carries the
        // destination account + no application fee (bps is 0 in test).
        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        $sessions = $gateway->paymentSessions;
        $last = end($sessions);
        $this->assertIsArray($last);
        $this->assertSame($accountId, $last['destination'] ?? null);
        $this->assertNull($last['fee']);
    }

    private function accountIdFor(string $spaceId): ?string
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $space = $em->getRepository(Space::class)->find($spaceId);
        assert($space instanceof Space);

        return $space->getStripeConnectAccountId();
    }

    private function createSharedSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Studio')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())
            ->setUser($admin)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $memberMembership = (new SpaceMembership())
                ->setUser($member)
                ->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($memberMembership);
            $this->entityManager->persist($memberMembership);
        }
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
