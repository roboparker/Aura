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
