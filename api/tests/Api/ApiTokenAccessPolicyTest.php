<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\ApiToken;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A scoped API token authenticating the REST API (main firewall) is gated by
 * its AccessPolicy — the same engine as admin impersonation.
 */
class ApiTokenAccessPolicyTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ApiToken')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testUnrestrictedTokenActsAsOwner(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Mine');
        $bearer = $this->mintToken($alice, null);

        $client = static::createClient();
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['title' => 'Edited via token'],
            'headers' => $this->authHeaders($bearer, 'application/merge-patch+json'),
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testViewPolicyAllowsReadBlocksWrite(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Mine');
        $bearer = $this->mintToken($alice, ['categories' => ['tasks' => 'view'], 'items' => []]);

        $client = static::createClient();

        $client->request('GET', '/tasks/' . $task->getId(), [
            'headers' => $this->authHeaders($bearer),
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['title' => 'Nope'],
            'headers' => $this->authHeaders($bearer, 'application/merge-patch+json'),
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testNonePolicyFiltersListAndBlocksItem(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Mine');
        $bearer = $this->mintToken($alice, ['categories' => ['tasks' => 'none'], 'items' => []]);

        $client = static::createClient();

        $client->request('GET', '/tasks', ['headers' => $this->authHeaders($bearer)]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 0]);

        $client->request('GET', '/tasks/' . $task->getId(), [
            'headers' => $this->authHeaders($bearer),
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testItemOverrideElevatesOnToken(): void
    {
        $alice = $this->createUser('alice@example.com');
        $granted = $this->createTask($alice, 'Granted');
        $this->createTask($alice, 'Hidden');
        $bearer = $this->mintToken($alice, [
            'categories' => ['tasks' => 'none'],
            'items' => ['task' => [(string) $granted->getId() => 'view']],
        ]);

        $client = static::createClient();
        $client->request('GET', '/tasks', ['headers' => $this->authHeaders($bearer)]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 1]);
    }

    public function testProfileNoneBlocksAccountRead(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bearer = $this->mintToken($alice, ['categories' => ['profile' => 'none'], 'items' => []]);

        $client = static::createClient();
        $client->request('GET', '/api/me', ['headers' => $this->authHeaders($bearer)]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testProfileViewAllowsAccountReadButWritesStayBlocked(): void
    {
        // Mint both tokens before createClient() reboots the kernel (which
        // would detach $alice from the EntityManager mid-test).
        $alice = $this->createUser('alice@example.com');
        $view = $this->mintToken($alice, ['categories' => ['profile' => 'view'], 'items' => []]);
        $edit = $this->mintToken($alice, ['categories' => ['profile' => 'edit'], 'items' => []]);

        $client = static::createClient();
        $client->request('GET', '/api/me', ['headers' => $this->authHeaders($view)]);
        $this->assertResponseIsSuccessful();

        // Even at 'edit', preference writes are account-sensitive and stay blocked.
        $client->request('PATCH', '/me/preferences', [
            'json' => ['timezone' => 'UTC'],
            'headers' => $this->authHeaders($edit),
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSecurityCategoryGatesAccountSurface(): void
    {
        $alice = $this->createUser('alice@example.com');
        $none = $this->mintToken($alice, ['categories' => ['security' => 'none'], 'items' => []]);
        $view = $this->mintToken($alice, ['categories' => ['security' => 'view'], 'items' => []]);

        $client = static::createClient();
        $client->request('GET', '/me/sessions', ['headers' => $this->authHeaders($none)]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('GET', '/me/sessions', ['headers' => $this->authHeaders($view)]);
        $this->assertResponseIsSuccessful();
    }

    public function testTokenOmittingAccountCategoryKeepsPriorReadBehavior(): void
    {
        // Backward-compat + impersonation safety: a policy that doesn't model
        // profile/security leaves account reads allowed as before.
        $alice = $this->createUser('alice@example.com');
        $bearer = $this->mintToken($alice, ['categories' => ['tasks' => 'view'], 'items' => []]);

        $client = static::createClient();
        $client->request('GET', '/api/me', ['headers' => $this->authHeaders($bearer)]);
        $this->assertResponseIsSuccessful();
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $bearer, ?string $contentType = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $bearer, 'Accept' => 'application/ld+json'];
        if (null !== $contentType) {
            $headers['Content-Type'] = $contentType;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed>|null $accessPolicy
     */
    private function mintToken(User $user, ?array $accessPolicy): string
    {
        $plain = ApiToken::PLAINTEXT_PREFIX . bin2hex(random_bytes(16));
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName('Test');
        $token->setTokenHash(hash('sha256', $plain));
        $token->setAccessPolicy($accessPolicy);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plain;
    }

    private function createTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    /**
     * @param string[] $roles
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
