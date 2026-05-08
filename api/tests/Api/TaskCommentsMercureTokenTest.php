<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TaskCommentsMercureTokenTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testUnauthenticatedRequestRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Personal task');

        $client = static::createClient();
        $client->request('GET', '/tasks/' . $task->getId() . '/comments/mercure-token');
        $this->assertNotSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTaskOwnerGetsTokenAndCookie(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Personal task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks/' . $task->getId() . '/comments/mercure-token');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'topic' => '/tasks/' . $task->getId() . '/comments',
        ]);

        // The mercureAuthorization cookie is set on the response by the
        // Symfony Mercure listener after the controller stages it. The
        // ApiPlatform test Response wrapper hides headers/cookies, so we
        // unwrap to the underlying HttpFoundation response.
        $cookies = $client->getResponse()->getKernelResponse()->headers->getCookies();
        $names = array_map(static fn ($c) => $c->getName(), $cookies);
        $this->assertContains('mercureAuthorization', $names, 'Subscriber cookie must be set on the response.');
    }

    public function testProjectMemberGetsToken(): void
    {
        $owner = $this->createUser('owner@example.com');
        $member = $this->createUser('member@example.com');
        $project = $this->createSharedProject($owner, [$owner, $member]);
        $task = $this->createTaskInProject($owner, $project, 'Project task');

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('GET', '/tasks/' . $task->getId() . '/comments/mercure-token');

        $this->assertResponseIsSuccessful();
    }

    public function testStrangerCannotGetToken(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $task = $this->createTask($alice, 'Private task');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/tasks/' . $task->getId() . '/comments/mercure-token');

        // 404 (not 403) — same enumeration-resistance rule the Task GET uses.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownTaskReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks/0193ffff-9999-7999-8999-999999999999/comments/mercure-token');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidUuidReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks/not-a-uuid/comments/mercure-token');

        $this->assertResponseStatusCodeSame(404);
    }

    private function createTask(User $owner, string $title, ?Project $project = null): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        if (null !== $project) {
            $task->setProject($project);
        }
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    private function createTaskInProject(User $owner, Project $project, string $title): Task
    {
        return $this->createTask($owner, $title, $project);
    }

    /**
     * @param User[] $members
     */
    private function createSharedProject(User $owner, array $members): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle('Shared');
        foreach ($members as $m) {
            $this->addProjectMember($project, $m);
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
