<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\User;
use App\Service\WaitlistSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class WaitlistTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\AppSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testSignupStatusReflectsSetting(): void
    {
        $client = static::createClient();

        $this->setWaitlist(false);
        $client->request('GET', '/signup-status');
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->boolField($this->body($client), 'waitlistEnabled'));

        $this->setWaitlist(true);
        $client->request('GET', '/signup-status');
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->boolField($this->body($client), 'waitlistEnabled'));
    }

    public function testSignupWhileWaitlistedCreatesWaitlistedUser(): void
    {
        $client = static::createClient();
        $this->setWaitlist(true);

        $client->request('POST', '/users', [
            'json' => [
                'email' => 'waiter@example.com',
                'plainPassword' => 'Password123!@#',
                'givenName' => 'Wait',
                'familyName' => 'Lister',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $user = $this->reloadUser('waiter@example.com');
        $this->assertTrue($user->isWaitlisted());
        $this->assertSame(['ROLE_WAITLISTED'], $user->getRoles());
    }

    public function testSignupWhenOpenCreatesNormalUser(): void
    {
        $client = static::createClient();
        $this->setWaitlist(false);

        $client->request('POST', '/users', [
            'json' => [
                'email' => 'open@example.com',
                'plainPassword' => 'Password123!@#',
                'givenName' => 'Open',
                'familyName' => 'Signup',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $user = $this->reloadUser('open@example.com');
        $this->assertFalse($user->isWaitlisted());
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testWaitlistedUserCanReadMeButIsBlockedElsewhere(): void
    {
        $user = $this->createTestUser('boxed@example.com', 'Password123!@#');
        $user->setWaitlisted(true);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($user);

        // /api/me works (the gate needs the client to learn it's waitlisted)…
        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->boolField($this->body($client), 'waitlisted'));

        // …but a ROLE_USER-gated endpoint is forbidden (no ROLE_USER).
        $client->request('GET', '/api-tokens');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminEndpointsRequireAdmin(): void
    {
        $user = $this->createTestUser('plain@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('GET', '/admin/waitlist');
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/waitlist', [
            'json' => ['waitlistEnabled' => false],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/waitlist/promote', [
            'json' => ['userId' => (string) $user->getId()],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminGetListsWaitlistedUsers(): void
    {
        $alpha = $this->createTestUser('alpha@example.com', 'Password123!@#');
        $alpha->setWaitlisted(true);
        $beta = $this->createTestUser('beta@example.com', 'Password123!@#');
        $beta->setWaitlisted(true);
        $admin = $this->createTestUser('admin@example.com', 'Password123!@#', ['ROLE_ADMIN']);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('GET', '/admin/waitlist');
        $this->assertResponseIsSuccessful();

        $body = $this->body($client);
        $this->assertSame(2, $this->intField($body, 'waitlistedCount'));

        $users = $this->arrayField($body, 'users');
        $this->assertCount(2, $users);
        $emails = [];
        foreach ($users as $u) {
            $this->assertIsArray($u);
            $emails[] = $this->stringField($u, 'email');
        }
        sort($emails);
        // Admin (not waitlisted) is excluded; both waitlisted users are listed.
        $this->assertSame(['alpha@example.com', 'beta@example.com'], $emails);
    }

    public function testPromoteSingleUserWhileWaitlistStaysOn(): void
    {
        $this->setWaitlist(true);

        $keep = $this->createTestUser('keep@example.com', 'Password123!@#');
        $keep->setWaitlisted(true);
        $grant = $this->createTestUser('grant@example.com', 'Password123!@#');
        $grant->setWaitlisted(true);
        $admin = $this->createTestUser('admin@example.com', 'Password123!@#', ['ROLE_ADMIN']);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/admin/waitlist/promote', [
            'json' => ['userId' => (string) $grant->getId()],
        ]);
        $this->assertResponseIsSuccessful();

        $body = $this->body($client);
        $this->assertTrue($this->boolField($body, 'promoted'));
        $this->assertSame(1, $this->intField($body, 'waitlistedCount'));
        // One access email went out — asserted before any further request, since
        // the mailer profiler reflects only the most recent request.
        $this->assertEmailCount(1);

        // Only the granted user is promoted; the other stays on the waitlist.
        $this->assertFalse($this->reloadUser('grant@example.com')->isWaitlisted());
        $this->assertTrue($this->reloadUser('keep@example.com')->isWaitlisted());

        // Waitlist mode itself is unchanged.
        $client->request('GET', '/signup-status');
        $this->assertTrue($this->boolField($this->body($client), 'waitlistEnabled'));
    }

    public function testPromoteRejectsNonWaitlistedUser(): void
    {
        $admin = $this->createTestUser('admin@example.com', 'Password123!@#', ['ROLE_ADMIN']);
        $normal = $this->createTestUser('normal@example.com', 'Password123!@#');
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/admin/waitlist/promote', [
            'json' => ['userId' => (string) $normal->getId()],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testTogglingWaitlistOffPromotesAndEmails(): void
    {
        $this->setWaitlist(true);

        $waiter = $this->createTestUser('promoted@example.com', 'Password123!@#');
        $waiter->setWaitlisted(true);
        $admin = $this->createTestUser('admin@example.com', 'Password123!@#', ['ROLE_ADMIN']);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/admin/waitlist', [
            'json' => ['waitlistEnabled' => false],
        ]);
        $this->assertResponseIsSuccessful();

        $body = $this->body($client);
        $this->assertFalse($this->boolField($body, 'waitlistEnabled'));
        $this->assertSame(1, $this->intField($body, 'promotedCount'));

        // The previously-waitlisted account is now a full user…
        $promoted = $this->reloadUser('promoted@example.com');
        $this->assertFalse($promoted->isWaitlisted());
        $this->assertContains('ROLE_USER', $promoted->getRoles());

        // …and got exactly one "you're in" email.
        $this->assertEmailCount(1);
    }

    private function setWaitlist(bool $enabled): void
    {
        static::getContainer()->get(WaitlistSettings::class)->setEnabled($enabled);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function body(Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        return $response->toArray();
    }

    private function reloadUser(string $email): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        assert($user instanceof User);
        return $user;
    }

    /**
     * @param list<string> $roles
     */
    private function createTestUser(string $email, string $plainPassword, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
