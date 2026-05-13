<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end coverage for the audit log: drives the entities through
 * normal API calls so Gedmo's listener actually fires (skipping the
 * direct entityManager.flush path would never log anything), then
 * verifies what the activity controllers return.
 */
class ActivityHistoryTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Wipe in dependency order. ext_log_entries has no FK so we
        // truncate it via DQL last to keep cross-test runs clean.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ActivityLog')->execute();
    }

    public function testTaskCreationAndUpdateAreLogged(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);

        // Create the task via the API so the LoggableListener actually
        // fires — the unit-of-work has to be the same one Gedmo
        // observes.
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Initial title'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $created = $response->toArray();
        $taskId = $created['id'];

        // Mutate a versioned field so an UPDATE row joins the CREATE row.
        $client->request('PATCH', '/tasks/' . $taskId, [
            'json' => ['title' => 'Renamed title'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Activity feed contains both, newest first.
        $client->request('GET', '/tasks/' . $taskId . '/activity');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();

        $this->assertSame(2, $body['totalItems']);
        $this->assertCount(2, $body['items']);
        $this->assertSame('update', $body['items'][0]['action']);
        $this->assertSame('Renamed title', $body['items'][0]['data']['title']);
        $this->assertSame('create', $body['items'][1]['action']);
        // Actor map is keyed by IRI so the PWA can render avatars.
        $this->assertArrayHasKey('/users/' . $alice->getId(), $body['actors']);
    }

    public function testNonReadableTaskActivityIs404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $task = $this->seedTask($alice, 'Private');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/tasks/' . $task->getId() . '/activity');

        // 404 (not 403) — same enumeration-resistance rule as Task GET.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testProjectMemberSeesProjectAndTaskActivity(): void
    {
        $owner = $this->createUser('owner@example.com');
        $member = $this->createUser('member@example.com');
        $project = $this->seedProject($owner, [$owner, $member], 'Shared project');

        $client = static::createClient();
        $client->loginUser($owner);
        // Create a task in the project via API so it gets a CREATE log.
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Project task', 'project' => '/projects/' . $project->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Update the project itself so it picks up an UPDATE log.
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['description' => 'Updated description'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Other member reads the feed — should see both rows
        // (project update + the task creation).
        $client->loginUser($member);
        $client->request('GET', '/projects/' . $project->getId() . '/activity');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertGreaterThanOrEqual(2, $body['totalItems']);

        $classes = array_column($body['items'], 'objectClass');
        $this->assertContains('Project', $classes, 'Feed should include the project update.');
        $this->assertContains('Task', $classes, 'Feed should include the in-project task creation.');
    }

    public function testStrangerCannotReadProjectActivity(): void
    {
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $project = $this->seedProject($owner, [$owner], 'Private project');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/projects/' . $project->getId() . '/activity');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidIdReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks/not-a-uuid/activity');
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/projects/not-a-uuid/activity');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPaginationCapsAndPagesCorrectly(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Pageable'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $taskId = $response->toArray()['id'];

        // Generate enough log rows to exercise pagination — five
        // updates → six total entries (one create + five updates).
        for ($i = 1; $i <= 5; $i++) {
            $client->request('PATCH', '/tasks/' . $taskId, [
                'json' => ['title' => 'Pageable v' . $i],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
            ]);
        }

        $client->request('GET', '/tasks/' . $taskId . '/activity?page=1&itemsPerPage=2');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame(6, $body['totalItems']);
        $this->assertCount(2, $body['items']);
        $this->assertSame('Pageable v5', $body['items'][0]['data']['title']);
    }

    private function seedTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    /**
     * @param User[] $members
     */
    private function seedProject(User $owner, array $members, string $title): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        foreach ($members as $member) {
            $this->addProjectMember($project, $member);
        }
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
    }

    private function createUser(string $email): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        return $user;
    }
}
