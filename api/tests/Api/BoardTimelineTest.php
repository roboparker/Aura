<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\CustomField\CustomFieldKind;
use App\Entity\Board;
use App\Entity\GlobalCustomFieldDefinition;
use App\Entity\Task;
use App\Entity\TaskRelationship;
use App\Entity\User;
use App\Repository\GlobalCustomFieldDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Board Timeline (#timeline): the `timelineEnabled` toggle wires the board to
 * the single canonical global "Start date" field — enabling attaches it, and it
 * can't be detached while enabled. Plus the batched dependency-edges endpoint
 * for the Gantt arrows.
 */
class BoardTimelineTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\TaskRelationship')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->ensureCanonicalStartField();
    }

    /**
     * Re-seed the canonical Start date field if it's missing.
     *
     * The migration seeds it, but a sibling suite
     * ({@see GlobalCustomFieldDefinitionTest}) truncates the whole table in its
     * own setUp — and since migrations are already applied, the row never comes
     * back. That made this suite pass only on a freshly-migrated database (CI)
     * and fail on any developer's box that had run the other suite first.
     * Seeding here makes these tests independent of both migration state and
     * execution order.
     */
    private function ensureCanonicalStartField(): void
    {
        $repo = static::getContainer()->get(GlobalCustomFieldDefinitionRepository::class);
        if (null !== $repo->findTimelineStartField()) {
            return;
        }

        $field = new GlobalCustomFieldDefinition();
        $field->setName('Start date')
            ->setKind(CustomFieldKind::DATE->value)
            ->setSubtype('date')
            ->setConfig([])
            ->setSystemKey(GlobalCustomFieldDefinition::SYSTEM_TIMELINE_START);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
    }

    public function testEnablingTimelineAttachesTheCanonicalStartField(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createBoard($alice);
        $startFieldIri = '/global_custom_field_definitions/' . $this->startField()->getId();

        $client = static::createClient();
        $client->loginUser($alice);

        $body = $client->request('PATCH', '/boards/' . $board->getId(), [
            'json' => ['timelineEnabled' => true],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertResponseIsSuccessful();

        $this->assertTrue($body['timelineEnabled'] ?? false);
        // Enabling is one action — the Start date field is now on the board.
        $fields = $body['globalCustomFieldDefinitions'] ?? [];
        $this->assertIsArray($fields);
        $this->assertContains($startFieldIri, $fields);
    }

    public function testTheStartFieldStaysAttachedWhileTimelineEnabled(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createBoard($alice);
        $startFieldIri = '/global_custom_field_definitions/' . $this->startField()->getId();

        $client = static::createClient();
        $client->loginUser($alice);

        // Enable (attaches the field), then try to drop all global fields while
        // still enabled. The processor re-adds the timeline field, so it can't
        // be removed without first turning the feature off.
        $client->request('PATCH', '/boards/' . $board->getId(), [
            'json' => ['timelineEnabled' => true],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $body = $client->request('PATCH', '/boards/' . $board->getId(), [
            'json' => ['globalCustomFieldDefinitions' => []],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $fields = $body['globalCustomFieldDefinitions'] ?? [];
        $this->assertIsArray($fields);
        $this->assertContains($startFieldIri, $fields);
    }

    public function testDisablingTimelineThenRemovingTheFieldIsAllowed(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createBoard($alice);

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('PATCH', '/boards/' . $board->getId(), [
            'json' => ['timelineEnabled' => true],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Turning the feature off first is the sanctioned way to drop the field.
        $body = $client->request('PATCH', '/boards/' . $board->getId(), [
            'json' => ['timelineEnabled' => false, 'globalCustomFieldDefinitions' => []],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertFalse($body['timelineEnabled'] ?? true);
    }

    public function testTheCanonicalStartFieldCannotBeDeleted(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);

        $client = static::createClient();
        $client->loginUser($admin);

        // Even an admin can't delete a system field — the feature depends on it.
        $client->request('DELETE', '/global_custom_field_definitions/' . $this->startField()->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDependencyEdgesAreScopedToTheBoard(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createBoard($alice);
        $other = $this->createBoard($alice, 'Other board');

        $a = $this->seedTask($board, $alice, 'A');
        $b = $this->seedTask($board, $alice, 'B');
        $external = $this->seedTask($other, $alice, 'External');

        $this->seedRelationship($a, $b, TaskRelationship::TYPE_REQUIRED);
        $this->seedRelationship($b, $external, TaskRelationship::TYPE_REQUIRED);
        $this->seedRelationship($a, $b, TaskRelationship::TYPE_RELATED);

        $client = static::createClient();
        $client->loginUser($alice);

        $body = $client->request('GET', '/boards/' . $board->getId() . '/dependencies')->toArray();
        $edges = $body['edges'];
        $this->assertIsArray($edges);
        $this->assertCount(1, $edges);
        $edge = $edges[0];
        $this->assertIsArray($edge);
        $this->assertSame((string) $a->getId(), $edge['source']);
        $this->assertSame((string) $b->getId(), $edge['target']);
    }

    public function testDependencyEndpointHidesUnreadableBoards(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $board = $this->createBoard($alice);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/boards/' . $board->getId() . '/dependencies');
        $this->assertResponseStatusCodeSame(404);
    }

    // ---- fixtures -------------------------------------------------------

    private function startField(): GlobalCustomFieldDefinition
    {
        $repo = static::getContainer()->get(GlobalCustomFieldDefinitionRepository::class);
        $field = $repo->findTimelineStartField();
        assert($field instanceof GlobalCustomFieldDefinition);

        return $field;
    }

    private function createBoard(User $owner, string $title = 'Roadmap'): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
    }

    private function seedTask(Board $board, User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setBoard($board);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    private function seedRelationship(Task $source, Task $target, string $type): void
    {
        $rel = new TaskRelationship();
        $rel->setSource($source)->setTarget($target)->setType($type);
        $this->entityManager->persist($rel);
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $roles
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
