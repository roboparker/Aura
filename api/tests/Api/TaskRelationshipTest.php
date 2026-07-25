<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Board;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task relationships: create a directed link, read it back from each side with
 * the right label, and the self / duplicate guards.
 */
class TaskRelationshipTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\TaskRelationship')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCreateReadFromBothSidesAndDelete(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $client = static::createClient();
        $client->loginUser($alice);

        $a = $this->createTask($client, $board, 'Design API');
        $b = $this->createTask($client, $board, 'Build endpoint');

        // A is parent of B.
        $relationship = $client->request('POST', '/task_relationships', [
            'json' => ['source' => $a, 'target' => $b, 'type' => 'parent'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $relIri = $relationship['@id'];
        $this->assertIsString($relIri);

        // From A's side: outgoing "parent of" B.
        $fromA = $client->request('GET', $a . '/relationships')->toArray();
        $rowsA = $fromA['relationships'];
        $this->assertIsArray($rowsA);
        $this->assertCount(1, $rowsA);
        $rowA = $rowsA[0];
        $this->assertIsArray($rowA);
        $this->assertSame('outgoing', $rowA['direction']);
        $this->assertSame('parent of', $rowA['label']);
        $taskA = $rowA['task'];
        $this->assertIsArray($taskA);
        $this->assertSame('Build endpoint', $taskA['title']);

        // From B's side: incoming "child of" A.
        $fromB = $client->request('GET', $b . '/relationships')->toArray();
        $rowsB = $fromB['relationships'];
        $this->assertIsArray($rowsB);
        $rowB = $rowsB[0];
        $this->assertIsArray($rowB);
        $this->assertSame('incoming', $rowB['direction']);
        $this->assertSame('child of', $rowB['label']);

        // Delete it.
        $client->request('DELETE', $relIri);
        $this->assertResponseStatusCodeSame(204);
        $after = $client->request('GET', $a . '/relationships')->toArray();
        $this->assertSame([], $after['relationships']);
    }

    public function testRejectsSelfAndDuplicate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $client = static::createClient();
        $client->loginUser($alice);

        $a = $this->createTask($client, $board, 'A');
        $b = $this->createTask($client, $board, 'B');

        // Self-relationship → 422.
        $client->request('POST', '/task_relationships', [
            'json' => ['source' => $a, 'target' => $a, 'type' => 'related'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // First related link is fine.
        $client->request('POST', '/task_relationships', [
            'json' => ['source' => $a, 'target' => $b, 'type' => 'related'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // The reverse of a same-type link → 422 (duplicate, either direction).
        $client->request('POST', '/task_relationships', [
            'json' => ['source' => $b, 'target' => $a, 'type' => 'related'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testSubtaskInvariantsAndProgressRollup(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $client = static::createClient();
        $client->loginUser($alice);

        $parent = $this->createTask($client, $board, 'Parent');
        $childA = $this->createTask($client, $board, 'Child A');
        $childB = $this->createTask($client, $board, 'Child B');
        $other = $this->createTask($client, $board, 'Other parent');

        $this->linkParent($client, $parent, $childA);
        $this->linkParent($client, $parent, $childB);

        // A child has exactly one parent — a second parent link is rejected.
        $client->request('POST', '/task_relationships', [
            'json' => ['source' => $other, 'target' => $childA, 'type' => 'parent'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // No cycles: the child can't become the parent of its own parent.
        $client->request('POST', '/task_relationships', [
            'json' => ['source' => $childA, 'target' => $parent, 'type' => 'parent'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // The subtasks endpoint reports both children, none done yet.
        $before = $client->request('GET', $parent . '/subtasks')->toArray();
        $this->assertSame(2, $before['total']);
        $this->assertSame(0, $before['completed']);
        $subtasks = $before['subtasks'];
        $this->assertIsArray($subtasks);
        $this->assertCount(2, $subtasks);

        // Completing a child bumps the rollup but never touches the parent.
        $client->request('PATCH', $childA, [
            'json' => ['completedOn' => (new \DateTimeImmutable())->format(\DATE_ATOM)],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $after = $client->request('GET', $parent . '/subtasks')->toArray();
        $this->assertSame(2, $after['total']);
        $this->assertSame(1, $after['completed']);

        // The parent is untouched by its children's completion (issue answer:
        // nothing automatic).
        $parentRow = $client->request('GET', $parent)->toArray();
        $this->assertArrayNotHasKey('completedOn', $parentRow);

        // From the child's side, the endpoint surfaces its parent link.
        $childView = $client->request('GET', $childA . '/subtasks')->toArray();
        $this->assertSame(0, $childView['total']);
        $parentInfo = $childView['parent'];
        $this->assertIsArray($parentInfo);
        $this->assertSame('Parent', $parentInfo['title']);
    }

    private function linkParent(Client $client, string $parent, string $child): void
    {
        $client->request('POST', '/task_relationships', [
            'json' => ['source' => $parent, 'target' => $child, 'type' => 'parent'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    /**
     * The rollup that backs the "2/5" badge on task rows. Attached by
     * TaskSubtaskProgressProvider in one batched query for the whole page.
     */
    public function testCollectionCarriesSubtaskProgress(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $client = static::createClient();
        $client->loginUser($alice);

        $parent = $this->createTask($client, $board, 'Parent');
        $doneChild = $this->createTask($client, $board, 'Done child');
        $openChild = $this->createTask($client, $board, 'Open child');
        $lonely = $this->createTask($client, $board, 'No children');

        foreach ([$doneChild, $openChild] as $child) {
            $client->request('POST', '/task_relationships', [
                'json' => ['source' => $parent, 'target' => $child, 'type' => 'parent'],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ]);
            $this->assertResponseStatusCodeSame(201);
        }

        $client->request('PATCH', $doneChild, [
            'json' => ['completedOn' => (new \DateTimeImmutable())->format(\DATE_ATOM)],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $body = $client->request('GET', '/tasks?itemsPerPage=50')->toArray();
        $members = $body['member'] ?? $body['hydra:member'] ?? [];
        $this->assertIsArray($members);

        $byIri = [];
        foreach ($members as $row) {
            $this->assertIsArray($row);
            $iri = $row['@id'];
            $this->assertIsString($iri);
            $byIri[$iri] = $row;
        }

        $parentRow = $byIri[$parent] ?? null;
        $this->assertIsArray($parentRow);
        $this->assertSame(['total' => 2, 'completed' => 1], $parentRow['subtaskProgress']);

        // A task with no children still carries the zeroed shape, so the client
        // never has to null-check before reading it.
        $lonelyRow = $byIri[$lonely] ?? null;
        $this->assertIsArray($lonelyRow);
        $this->assertSame(['total' => 0, 'completed' => 0], $lonelyRow['subtaskProgress']);
    }

    private function createTask(Client $client, Board $board, string $title): string
    {
        $task = $client->request('POST', '/tasks', [
            'json' => ['title' => $title, 'board' => '/boards/' . $board->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $iri = $task['@id'];
        $this->assertIsString($iri);

        return $iri;
    }

    private function createProject(User $owner, string $title): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        $this->addBoardMember($board, $owner);
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
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
