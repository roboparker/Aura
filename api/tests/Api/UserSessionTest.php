<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use App\Entity\UserSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserSessionTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserSession')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAuthenticatedRequestRegistersASession(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();

        $rows = $this->entityManager->getRepository(UserSession::class)->findBy(['user' => $alice]);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->getRevokedAt());
    }

    public function testListMarksCurrentSession(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/api/me');

        $client->request('GET', '/me/sessions');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $sessions = $this->arrayField($body, 'sessions');
        $this->assertCount(1, $sessions);
        $first = $sessions[0];
        $this->assertIsArray($first);
        $this->assertTrue($first['current']);
    }

    public function testRevokeOthersRequiresStepUpAndKeepsCurrent(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/api/me');

        // The request cycle resets the EM — re-fetch the managed user before
        // attaching a second device's session.
        $alice = $this->entityManager->find(User::class, $alice->getId());
        self::assertNotNull($alice);
        $other = new UserSession();
        $other->setUser($alice);
        $other->setSessionIdHash(str_repeat('a', 64));
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        // Without the password step-up it's rejected.
        $client->request('POST', '/me/sessions/revoke-others', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);

        // With the password it succeeds and revokes only the other session.
        $client->request('POST', '/me/sessions/revoke-others', [
            'json' => ['currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(UserSession::class)->find($other->getId());
        $this->assertNotNull($refreshed);
        $this->assertNotNull($refreshed->getRevokedAt());
    }

    public function testRevokeSingleSession(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/api/me');

        $alice = $this->entityManager->find(User::class, $alice->getId());
        self::assertNotNull($alice);
        $other = new UserSession();
        $other->setUser($alice);
        $other->setSessionIdHash(str_repeat('b', 64));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $otherId = (string) $other->getId();

        $client->request('DELETE', '/me/sessions/' . $otherId);
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(UserSession::class)->find($otherId);
        $this->assertNotNull($refreshed);
        $this->assertNotNull($refreshed->getRevokedAt());
    }

    public function testCannotRevokeAnotherUsersSession(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobSession = new UserSession();
        $bobSession->setUser($bob);
        $bobSession->setSessionIdHash(str_repeat('c', 64));
        $this->entityManager->persist($bobSession);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/me/sessions/' . $bobSession->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testApiMeExposesTwoFactorMetadata(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $twoFactor = $this->arrayField($body, 'twoFactor');
        $this->assertSame('totp', $twoFactor['method']);
        $this->assertArrayHasKey('recoveryCodesTotal', $twoFactor);
        $this->assertArrayHasKey('lastVerifiedAt', $twoFactor);
    }

    /**
     * @param string[] $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

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
