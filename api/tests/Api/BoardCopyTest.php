<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\CustomFieldDefinition;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /boards/{id}/copy` — clones a board (+ CFDs) into a
 * target space (#182).
 */
class BoardCopyTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Tag')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRequiresAuth(): void
    {
        static::createClient()->request('POST', '/boards/' . str_repeat('0', 36) . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCopiesIntoSourceSpaceByDefault(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'Template', $source);
        $this->seedDefinition($board, 'Severity', 'dropdown', ['low', 'med', 'high'], 0);
        $this->seedDefinition($board, 'Notes', 'text', null, 1);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('Template (copy)', $body['title']);
        $this->assertSame('/spaces/' . $source->getId(), $body['space']);

        $this->entityManager->clear();
        $copyId = $body['id'];
        $copy = $this->entityManager->getRepository(Board::class)->find($copyId);
        $this->assertNotNull($copy);
        $this->assertSame((string) $source->getId(), (string) $copy->getSpace()?->getId());
        $this->assertSame((string) $alice->getId(), (string) $copy->getOwner()?->getId());

        // CFDs cloned with same shape, attached to the new board.
        $defs = $this->definitionsForProjectId((string) $copy->getId());
        $this->assertCount(2, $defs);
        $this->assertSame('Severity', $defs[0]->getName());
        $this->assertSame('select.single', $defs[0]->getTypeKey());
        $options = $defs[0]->getConfig()['options'] ?? [];
        $this->assertIsArray($options);
        $this->assertSame(
            ['low', 'med', 'high'],
            array_map(
                static fn (mixed $o): mixed => is_array($o) ? $o['key'] ?? null : null,
                $options,
            ),
        );
        $this->assertSame('Notes', $defs[1]->getName());

        // Source board is untouched (its 2 CFDs still there).
        $sourceDefs = $this->definitionsForProjectId((string) $board->getId());
        $this->assertCount(2, $sourceDefs);
    }

    public function testCopiesIntoExplicitTargetSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('/spaces/' . $target->getId(), $body['space']);

        $this->entityManager->clear();
        $copy = $this->entityManager->getRepository(Board::class)->find($body['id']);
        $this->assertNotNull($copy);
        $this->assertSame((string) $target->getId(), (string) $copy->getSpace()?->getId());
    }

    public function testCopyDoesNotDoubleSuffix(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'Template (copy)', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame('Template (copy)', $response->toArray()['title']);
    }

    public function testCloneOwnerIsCurrentUserNotSourceOwner(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $source = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($source, $bob);
        $board = $this->createProject($alice, 'Alice template', $source);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copy = $this->entityManager->getRepository(Board::class)
            ->find($response->toArray()['id']);
        $this->assertNotNull($copy);
        $this->assertSame((string) $bob->getId(), (string) $copy->getOwner()?->getId());
    }

    public function testNonMemberOfSourceGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonMemberOfTargetGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $source = $this->createSpace($alice, 'Source');
        $this->ensureSpaceMembership($source, $bob);
        $target = $this->createSpace($alice, 'Target');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidSpaceIriGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => ['space' => 'not-a-real-iri'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testAcceptsBareUuidAsTarget(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => ['space' => (string) $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame('/spaces/' . $target->getId(), $response->toArray()['space']);
    }

    public function testIncludeTasksFalseLeavesTasksOnSourceOnly(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'Backend', $source);
        $this->seedTask($alice, $board, 'Buy milk', 0);
        $this->seedTask($alice, $board, 'Buy eggs', 1);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame(0, $response->toArray()['tasksCloned']);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copyId = $response->toArray()['id'];
        $copyTasks = $this->entityManager->getRepository(Task::class)
            ->findBy(['board' => $copyId]);
        $this->assertCount(0, $copyTasks);
    }

    public function testIncludeTasksClonesStructuralFields(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'Backend', $source);
        $dueDate = new \DateTimeImmutable('2026-12-01T00:00:00Z');
        $task = $this->seedTask($alice, $board, 'Plan launch', 0);
        $task->setDescription('Quarterly');
        $task->setDueDate($dueDate);
        $task->setRecurrenceRule(['frequency' => 'weekly', 'interval' => 1]);
        $task->setCompletedOn(new \DateTimeImmutable());
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => ['includeTasks' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame(1, $response->toArray()['tasksCloned']);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copyId = $response->toArray()['id'];
        /** @var Task[] $cloned */
        $cloned = $this->entityManager->getRepository(Task::class)
            ->findBy(['board' => $copyId]);
        $this->assertCount(1, $cloned);
        $this->assertSame('Plan launch', $cloned[0]->getTitle());
        $this->assertSame('Quarterly', $cloned[0]->getDescription());
        $this->assertEquals($dueDate, $cloned[0]->getDueDate());
        $this->assertSame(['frequency' => 'weekly', 'interval' => 1], $cloned[0]->getRecurrenceRule());
        $this->assertNull(
            $cloned[0]->getCompletedOn(),
            'Clone should be open even if the source was completed.',
        );
        $this->assertSame(
            (string) $alice->getId(),
            (string) $cloned[0]->getOwner()?->getId(),
            'Clone task owner = caller (template metaphor).',
        );
    }

    public function testIncludeTasksCarriesOnlyTagsOwnedByCaller(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $source = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($source, $bob);
        $board = $this->createProject($alice, 'P', $source);
        $aliceTag = $this->seedTag($alice, 'alice-tag', $source);
        $bobTag = $this->seedTag($bob, 'bob-tag', $source);
        $task = $this->seedTask($alice, $board, 'Tagged task', 0);
        $task->addTag($aliceTag);
        $task->addTag($bobTag);
        $this->entityManager->flush();

        // Alice copies — both tags belong to her view of the task, but
        // bob-tag isn't hers, so the clone only keeps alice-tag.
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => ['includeTasks' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copyId = $response->toArray()['id'];
        /** @var Task[] $cloned */
        $cloned = $this->entityManager->getRepository(Task::class)
            ->findBy(['board' => $copyId]);
        $this->assertCount(1, $cloned);
        $tagTitles = array_map(
            fn (Tag $t) => $t->getTitle(),
            $cloned[0]->getTags()->toArray(),
        );
        $this->assertSame(['alice-tag'], array_values($tagTitles));
    }

    public function testIncludeTasksPreservesPositionOrder(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'P', $source);
        $this->seedTask($alice, $board, 'C', 2);
        $this->seedTask($alice, $board, 'A', 0);
        $this->seedTask($alice, $board, 'B', 1);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/copy', [
            'json' => ['includeTasks' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame(3, $response->toArray()['tasksCloned']);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copyId = $response->toArray()['id'];
        $cloned = $this->entityManager->getRepository(Task::class)
            ->findBy(['board' => $copyId], ['position' => 'ASC']);
        $titlesByPosition = array_map(fn (Task $t) => $t->getTitle(), $cloned);
        $this->assertSame(['A', 'B', 'C'], $titlesByPosition);
    }

    /**
     * Definitions attached to a board via the (space-owned) many-to-many.
     *
     * @return list<CustomFieldDefinition>
     */
    private function definitionsForProjectId(string $boardId): array
    {
        /** @var list<CustomFieldDefinition> $defs */
        $defs = $this->entityManager->createQuery(
            'SELECT d FROM App\Entity\CustomFieldDefinition d '
            . 'JOIN d.boards p WHERE p.id = :id ORDER BY d.position ASC',
        )->setParameter('id', $boardId)->getResult();
        return $defs;
    }

    /**
     * @param string[]|null $options
     *
     * @param list<string>|null $options
     */
    private function seedDefinition(Board $board, string $name, string $type, ?array $options = null, int $position = 0): CustomFieldDefinition
    {
        $def = new CustomFieldDefinition();
        $def->setBoard($board);
        $def->setName($name);
        $def->setType($type);
        if (null !== $options) {
            $def->setOptions($options);
        }
        $def->setPosition($position);
        $this->entityManager->persist($def);
        $this->entityManager->flush();
        return $def;
    }

    private function seedTask(User $owner, Board $board, string $title, int $position = 0): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setBoard($board);
        $task->setTitle($title);
        $task->setPosition($position);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    private function seedTag(User $owner, string $title, Space $space): Tag
    {
        $tag = new Tag();
        $tag->setOwner($owner);
        $tag->setSpace($space);
        $tag->setTitle($title);
        $tag->setColor('#0369a1');
        $this->entityManager->persist($tag);
        $this->entityManager->flush();
        return $tag;
    }

    private function createProject(User $owner, string $title, Space $space): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        $board->setSpace($space);
        $this->entityManager->persist($board);
        $this->entityManager->flush();
        return $board;
    }

    private function createSpace(User $owner, string $name): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($owner);
        $this->entityManager->persist($space);

        $admin = (new SpaceMembership())
            ->setUser($owner)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($admin);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        return $space;
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
