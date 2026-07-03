<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\SsoIdentity;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Social sign-in (#267). The OAuth round-trip itself needs a live provider, so
 * these cover the parts that don't: provider availability, access control, and
 * the linked-identity read/unlink surface. In the test env no provider client
 * ids are set, so every provider is "unconfigured".
 */
class SsoTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\SsoIdentity')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testStatusListsNoProvidersWhenUnconfigured(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/auth/sso/status');
        $this->assertResponseIsSuccessful();
        $providers = $response->toArray()['providers'] ?? null;
        $this->assertSame([], $providers);
    }

    public function testStartUnknownProviderIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/sso/google/start');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testIdentitiesRequireAuth(): void
    {
        static::createClient()->request('GET', '/me/sso/identities');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testConnectRequiresAuth(): void
    {
        static::createClient()->request('GET', '/me/sso/google/connect');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testIdentitiesEmptyForNewUser(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('alice@example.com'));
        $response = $client->request('GET', '/me/sso/identities');
        $this->assertResponseIsSuccessful();
        $body = $response->toArray();
        $this->assertSame([], $body['identities'] ?? null);
        $this->assertTrue($body['hasPassword'] ?? null);
    }

    public function testIdentitiesListLinkedProvider(): void
    {
        $user = $this->createUser('bob@example.com');
        $identity = (new SsoIdentity('google', 'google-sub-123'))->setEmail('bob@example.com');
        $user->addSsoIdentity($identity);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($user);
        $response = $client->request('GET', '/me/sso/identities');
        $this->assertResponseIsSuccessful();
        $identities = $response->toArray()['identities'] ?? [];
        $this->assertIsArray($identities);
        $this->assertCount(1, $identities);
        $first = $identities[0];
        $this->assertIsArray($first);
        $this->assertSame('google', $first['provider'] ?? null);
    }

    public function testUnlinkUnauthenticated(): void
    {
        static::createClient()->request('DELETE', '/me/sso/google');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUnlinkNotLinkedIsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('carol@example.com'));
        $client->request('DELETE', '/me/sso/google');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnlinkRemovesLinkedProvider(): void
    {
        $user = $this->createUser('dave@example.com');
        $identity = (new SsoIdentity('github', 'gh-1'))->setEmail('dave@example.com');
        $user->addSsoIdentity($identity);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($user);
        // Has a password, so removing the only linked identity is allowed.
        $client->request('DELETE', '/me/sso/github');
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $remaining = $this->entityManager->getRepository(SsoIdentity::class)
            ->findOneBy(['provider' => 'github', 'subject' => 'gh-1']);
        $this->assertNull($remaining);
    }

    /**
     * @param list<string> $roles
     */
    public function testAdminConfigRequiresAdmin(): void
    {
        static::createClient()->request('GET', '/admin/sso');
        $this->assertResponseStatusCodeSame(401);

        $client = static::createClient();
        $client->loginUser($this->createUser('plain@example.com'));
        $client->request('GET', '/admin/sso');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminConfiguresProviderAndItBecomesAvailable(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $client->request('PUT', '/admin/sso/google', [
            'json' => ['enabled' => true, 'clientId' => 'gid.apps', 'clientSecret' => 'shhh'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Now advertised by the public status endpoint.
        $status = static::createClient()->request('GET', '/auth/sso/status')->toArray();
        $this->assertContains('google', $status['providers'] ?? []);

        // Admin read shows the client id + a secret flag, never the secret.
        $listed = $client->request('GET', '/admin/sso')->toArray();
        $providers = $listed['providers'] ?? [];
        $this->assertIsArray($providers);
        $google = null;
        foreach ($providers as $p) {
            if (is_array($p) && ($p['provider'] ?? null) === 'google') {
                $google = $p;
            }
        }
        $this->assertIsArray($google);
        $this->assertTrue($google['enabled'] ?? null);
        $this->assertSame('gid.apps', $google['clientId'] ?? null);
        $this->assertTrue($google['hasClientSecret'] ?? null);
        $this->assertArrayNotHasKey('clientSecret', $google);
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#3b82f6');
        if ([] !== $roles) {
            $user->setRoles($roles);
        }
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
