<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ApiTokenTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\ApiToken')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCreateTokenReturnsPlaintextOnce(): void
    {
        $user = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/api-tokens', [
            'json' => ['name' => 'Local CLI'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('Local CLI', $body['name']);
        $this->assertArrayHasKey('plainToken', $body);
        $plainToken = $body['plainToken'];
        $this->assertIsString($plainToken);
        $this->assertStringStartsWith(ApiToken::PLAINTEXT_PREFIX, $plainToken);

        // The persisted hash should be the sha256 of the returned plaintext.
        $repo = $this->entityManager->getRepository(ApiToken::class);
        $token = $repo->findOneBy(['name' => 'Local CLI']);
        $this->assertNotNull($token);
        $this->assertSame(hash('sha256', $plainToken), $token->getTokenHash());
    }

    public function testListReturnsOnlyOwnTokens(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->createTokenFor($alice, 'Alice CLI');
        $this->createTokenFor($bob, 'Bob CLI');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/api-tokens');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? [];
        $this->assertIsArray($members);
        $names = array_column($members, 'name');
        $this->assertSame(['Alice CLI'], $names);
    }

    public function testDeleteOwnToken(): void
    {
        $alice = $this->createUser('alice@example.com');
        $token = $this->createTokenFor($alice, 'Disposable');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/api-tokens/' . $token->getId());

        $this->assertResponseStatusCodeSame(204);
        $this->assertNull(
            $this->entityManager->getRepository(ApiToken::class)->find($token->getId()),
        );
    }

    public function testCannotDeleteSomeoneElsesToken(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsToken = $this->createTokenFor($bob, 'Stay away');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/api-tokens/' . $bobsToken->getId());

        // Item-level filter hides Bob's token from Alice — 404 not 403.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testListUnauthenticated(): void
    {
        static::createClient()->request('GET', '/api-tokens');
        $this->assertResponseStatusCodeSame(401);
    }

    private function createTokenFor(User $user, string $name): ApiToken
    {
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName($name);
        $token->setTokenHash(hash('sha256', ApiToken::PLAINTEXT_PREFIX . bin2hex(random_bytes(8))));
        $this->entityManager->persist($token);
        $this->entityManager->flush();
        return $token;
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
