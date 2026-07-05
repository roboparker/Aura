<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task sections (board grouping): CRUD + space scoping, and assigning a task to
 * a section via the Task `section` write group.
 */
class TaskSectionTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\TaskSection')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testMemberCreatesSectionAndAssignsTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);

        // Create a section.
        $section = $client->request('POST', '/task_sections', [
            'json' => [
                'board' => '/boards/' . $board->getId(),
                'title' => 'Up next',
                'position' => 0,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $sectionIri = $section['@id'];
        $this->assertIsString($sectionIri);

        // It shows up in the board-scoped collection.
        $list = $client->request('GET', '/task_sections?board=/boards/' . $board->getId())
            ->toArray();
        $this->assertSame(1, $list['totalItems'] ?? null);

        // Assign a task to the section.
        $task = $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Wire it up',
                'board' => '/boards/' . $board->getId(),
                'section' => $sectionIri,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($sectionIri, $task['section'] ?? null);
    }

    public function testNonMemberCannotSeeSections(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $board = $this->createProject($alice, 'Private');

        $client = static::createClient();
        $client->loginUser($alice);
        $created = $client->request('POST', '/task_sections', [
            'json' => ['board' => '/boards/' . $board->getId(), 'title' => 'Secret'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $iri = $created['@id'];
        $this->assertIsString($iri);

        // The stranger's scoped list is empty and the item lookup 404s.
        $client->loginUser($stranger);
        $list = $client->request('GET', '/task_sections?board=/boards/' . $board->getId())
            ->toArray();
        $this->assertSame(0, $list['totalItems'] ?? null);

        $client->request('GET', $iri);
        $this->assertResponseStatusCodeSame(404);
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
