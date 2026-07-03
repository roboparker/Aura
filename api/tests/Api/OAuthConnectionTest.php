<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\OAuthConnection;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * OAuth account connections (#581). The OAuth round-trip needs a live provider,
 * so these cover access control, availability (no provider configured in the
 * test env), and the list/disconnect surface.
 */
class OAuthConnectionTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\OAuthConnection')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\AppSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListRequiresAuth(): void
    {
        static::createClient()->request('GET', '/me/connections');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testConnectUnconfiguredProviderIsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('a@example.com'));
        // No Google app configured in the test env → not connectable.
        $client->request('GET', '/me/connections/google/connect');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testConnectUnknownProviderIsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('b@example.com'));
        $client->request('GET', '/me/connections/dropbox/connect');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testListEmptyForNewUser(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('c@example.com'));
        $response = $client->request('GET', '/me/connections');
        $this->assertResponseIsSuccessful();
        $body = $response->toArray();
        $this->assertSame([], $body['connections'] ?? null);
        $this->assertSame([], $body['available'] ?? null);
    }

    public function testDisconnectNotConnectedIsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('d@example.com'));
        $client->request('DELETE', '/me/connections/google');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testListAndDisconnectShowsExistingConnection(): void
    {
        $user = $this->createUser('e@example.com');
        $conn = (new OAuthConnection('google'))
            ->setUser($user)
            ->setEmail('e@example.com')
            ->setAccessToken('enc-token')
            ->setScopes(['https://www.googleapis.com/auth/calendar']);
        $this->entityManager->persist($conn);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($user);
        $listed = $client->request('GET', '/me/connections')->toArray();
        $connections = $listed['connections'] ?? [];
        $this->assertIsArray($connections);
        $this->assertCount(1, $connections);

        $client->request('DELETE', '/me/connections/google');
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $this->assertNull(
            $this->entityManager->getRepository(OAuthConnection::class)->findOneBy(['provider' => 'google']),
        );
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#3b82f6');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
