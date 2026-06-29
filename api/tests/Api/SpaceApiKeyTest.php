<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\ApiToken;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Space-scoped API keys (#space-roles): a key authenticates via Bearer as its
 * creator but is confined to its space and narrowed to its roles' CRUD — it
 * must NOT be able to reach the creator's other spaces, and a no-role key can
 * do nothing.
 */
class SpaceApiKeyTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        foreach (['ApiToken', 'Task', 'SpaceRole', 'Project', 'Space', 'User'] as $entity) {
            $this->entityManager->createQuery("DELETE FROM App\\Entity\\$entity")->execute();
        }
    }

    public function testKeyReadsInSpaceButCannotWriteWithoutRole(): void
    {
        [$plain, , $task] = $this->scenario(['tasks' => ['read' => true]]);

        $client = static::createClient();
        $client->request('GET', '/tasks/' . $task->getId(), $this->bearer($plain));
        $this->assertResponseStatusCodeSame(200);

        $client->request('PATCH', '/tasks/' . $task->getId(), $this->bearerPatch($plain, ['title' => 'Nope']));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testKeyIsConfinedToItsSpace(): void
    {
        [$plain, $admin] = $this->scenario(['tasks' => ['read' => true, 'update' => true]]);
        // A second space the creator also admins, with its own task.
        $otherSpace = $this->createSpace($admin);
        $otherProject = $this->createProject($admin, $otherSpace, 'Other');
        $otherTask = $this->createTask($admin, $otherProject, 'Foreign');

        $client = static::createClient();
        // The key's creator IS a member of the other space, but the key can't
        // reach it (confinement) → 404 (existence-hiding).
        $client->request('GET', '/tasks/' . $otherTask->getId(), $this->bearer($plain));
        $this->assertResponseStatusCodeSame(404);

        $client->request(
            'PATCH',
            '/tasks/' . $otherTask->getId(),
            $this->bearerPatch($plain, ['title' => 'Cross-space write']),
        );
        $this->assertResponseStatusCodeSame(404);
    }

    public function testKeyCollectionListsOnlyItsSpace(): void
    {
        [$plain, $admin, $task] = $this->scenario(['tasks' => ['read' => true]]);
        $otherSpace = $this->createSpace($admin);
        $otherProject = $this->createProject($admin, $otherSpace, 'Other');
        $this->createTask($admin, $otherProject, 'Foreign');

        $client = static::createClient();
        $client->request('GET', '/tasks', $this->bearer($plain));
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? [];
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $first = $members[0];
        $this->assertIsArray($first);
        $this->assertSame((string) $task->getId(), $first['id'] ?? null);
    }

    public function testNoRoleKeyIsDenied(): void
    {
        [$plain, , $task] = $this->scenario([]); // no permissions

        $client = static::createClient();
        $client->request('GET', '/tasks/' . $task->getId(), $this->bearer($plain));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testKeyCannotReachNonContentSurface(): void
    {
        [$plain] = $this->scenario(['tasks' => ['read' => true]]);

        $client = static::createClient();
        $client->request('GET', '/spaces', $this->bearer($plain));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testManagementIsAdminOnly(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, $member);

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('POST', '/spaces/' . $space->getId() . '/api-keys', [
            'json' => ['name' => 'sneaky', 'roles' => []],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatesKeyAndGetsPlaintextOnce(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $role = $this->seedRole($space, ['tasks' => ['read' => true]]);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces/' . $space->getId() . '/api-keys', [
            'json' => ['name' => 'CI', 'roles' => ['/space_roles/' . $role->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $token = $body['plainToken'] ?? '';
        $this->assertIsString($token);
        $this->assertStringStartsWith(ApiToken::PLAINTEXT_PREFIX, $token);
    }

    public function testAdminListsAndRevokesKey(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $role = $this->seedRole($space, ['tasks' => ['read' => true]]);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces/' . $space->getId() . '/api-keys', [
            'json' => ['name' => 'Bot', 'roles' => ['/space_roles/' . $role->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $created = $client->getResponse();
        self::assertNotNull($created);
        $keyId = $created->toArray()['id'] ?? '';
        $this->assertIsString($keyId);

        $client->request('GET', '/spaces/' . $space->getId() . '/api-keys');
        $this->assertResponseStatusCodeSame(200);
        $listed = $client->getResponse();
        self::assertNotNull($listed);
        $keys = $listed->toArray()['keys'] ?? [];
        $this->assertIsArray($keys);
        $this->assertCount(1, $keys);
        $first = $keys[0];
        $this->assertIsArray($first);
        $this->assertSame('Bot', $first['name'] ?? null);

        $client->request('DELETE', '/spaces/' . $space->getId() . '/api-keys/' . $keyId);
        $this->assertResponseStatusCodeSame(204);

        $client->request('GET', '/spaces/' . $space->getId() . '/api-keys');
        $afterDelete = $client->getResponse();
        self::assertNotNull($afterDelete);
        $remaining = $afterDelete->toArray()['keys'] ?? [];
        $this->assertIsArray($remaining);
        $this->assertCount(0, $remaining);
    }

    /**
     * Build: admin + space + project + task + a scoped key (with the given
     * permissions) and return [plaintext, admin, task].
     *
     * @param array<string, array<string, bool>> $permissions
     * @return array{0: string, 1: User, 2: Task}
     */
    private function scenario(array $permissions): array
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $project = $this->createProject($admin, $space, 'Backend');
        $task = $this->createTask($admin, $project, 'Task');
        $role = $this->seedRole($space, $permissions);

        $plain = ApiToken::PLAINTEXT_PREFIX . 'testsecret_' . bin2hex(random_bytes(8));
        $key = (new ApiToken())
            ->setUser($admin)
            ->setSpace($space)
            ->setName('Key')
            ->setTokenHash(hash('sha256', $plain));
        if ([] !== $permissions) {
            $key->addRole($role);
        }
        $this->entityManager->persist($key);
        $this->entityManager->flush();

        return [$plain, $admin, $task];
    }

    /**
     * @return array<string, mixed>
     */
    private function bearer(string $plain): array
    {
        return ['headers' => ['Authorization' => 'Bearer ' . $plain]];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function bearerPatch(string $plain, array $json): array
    {
        return [
            'headers' => [
                'Authorization' => 'Bearer ' . $plain,
                'Content-Type' => 'application/merge-patch+json',
            ],
            'json' => $json,
        ];
    }

    /**
     * @param array<string, array<string, bool>> $permissions
     */
    private function seedRole(Space $space, array $permissions): SpaceRole
    {
        $role = (new SpaceRole())
            ->setSpace($space)
            ->setName('Key role ' . bin2hex(random_bytes(4)))
            ->setPermissions($permissions);
        $this->entityManager->persist($role);
        $this->entityManager->flush();

        return $role;
    }

    private function createSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Team')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $m = (new SpaceMembership())->setUser($member)->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($m);
            $this->entityManager->persist($m);
        }
        $this->entityManager->flush();

        return $space;
    }

    private function createProject(User $owner, Space $space, string $title): Project
    {
        $project = (new Project())->setOwner($owner)->setTitle($title)->setSpace($space);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function createTask(User $owner, Project $project, string $title): Task
    {
        $task = (new Task())->setOwner($owner)->setProject($project)->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
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
