<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\CustomField\CustomFieldKind;
use App\Entity\Board;
use App\Entity\CustomFieldDefinition;
use App\Entity\Task;
use App\Entity\TaskRelationship;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Board Timeline (#timeline): the opt-in start-field config on the board and
 * the batched dependency-edges endpoint. The bars themselves are computed
 * client-side from data the board page already has, so the backend surface is
 * just "which field is the start" + "which required links to draw".
 */
class BoardTimelineTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\TaskRelationship')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testConfiguringADateFieldAsTimelineStart(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createBoard($alice);
        $dateField = $this->seedField($board, 'Start', CustomFieldKind::DATE->value, 'date');

        $client = static::createClient();
        $client->loginUser($alice);

        $body = $client->request('PATCH', '/boards/' . $board->getId(), [
            'json' => ['timelineStartField' => '/custom_field_definitions/' . $dateField->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $configured = $body['timelineStartField'] ?? null;
        $this->assertIsString($configured);
        $this->assertStringContainsString((string) $dateField->getId(), $configured);
    }

    public function testANonDateFieldIsRejectedAsTheStart(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createBoard($alice);
        $textField = $this->seedField($board, 'Notes', CustomFieldKind::TEXT->value, 'text');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('PATCH', '/boards/' . $board->getId(), [
            'json' => ['timelineStartField' => '/custom_field_definitions/' . $textField->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        // A text field can't drive a date bar — 422, not a silent accept.
        $this->assertResponseStatusCodeSame(422);
    }

    public function testAFieldFromAnotherBoardIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createBoard($alice);
        $other = $this->createBoard($alice, 'Other board');
        $foreignField = $this->seedField($other, 'Start', CustomFieldKind::DATE->value, 'date');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('PATCH', '/boards/' . $board->getId(), [
            'json' => ['timelineStartField' => '/custom_field_definitions/' . $foreignField->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testDependencyEdgesAreScopedToTheBoard(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createBoard($alice);
        $other = $this->createBoard($alice, 'Other board');

        $a = $this->seedTask($board, $alice, 'A');
        $b = $this->seedTask($board, $alice, 'B');
        $external = $this->seedTask($other, $alice, 'External');

        // A required-for B (both in board) — should appear.
        $this->seedRelationship($a, $b, TaskRelationship::TYPE_REQUIRED);
        // B required-for a task in another board — must NOT appear (no bar to point at).
        $this->seedRelationship($b, $external, TaskRelationship::TYPE_REQUIRED);
        // A 'related' link between board tasks is not a dependency — excluded.
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

    private function createBoard(User $owner, string $title = 'Roadmap'): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
    }

    private function seedField(Board $board, string $name, string $kind, string $subtype): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
            ->setName($name)
            ->setKind($kind)
            ->setSubtype($subtype)
            ->setConfig([])
            ->setPosition(0);
        $board->addCustomFieldDefinition($field);
        $this->entityManager->persist($field);
        $this->entityManager->flush();

        return $field;
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
