<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Comment;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CommentTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // Notifications hold FKs to both Comment and Task (mention rows
        // and reminder rows respectively); wipe them first so the bulk
        // entity deletes below don't trip on orphans.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListRequiresAuth(): void
    {
        static::createClient()->request('GET', '/comments');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateCommentOnOwnTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Pick a domain');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => 'Started looking at registrars.',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Comment',
            'body' => 'Started looking at registrars.',
        ]);

        // Author was stamped server-side.
        $reloaded = $this->entityManager->getRepository(Comment::class)
            ->findOneBy(['body' => 'Started looking at registrars.']);
        $this->assertNotNull($reloaded);
        $this->assertTrue($alice->getId()->equals($reloaded->getAuthor()?->getId()));
    }

    public function testCreateRejectsClientSuppliedAuthor(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $task = $this->createTask($alice, 'Task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => 'Pretending to be Bob.',
                'author' => '/users/' . $bob->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $reloaded = $this->entityManager->getRepository(Comment::class)
            ->findOneBy(['body' => 'Pretending to be Bob.']);
        $this->assertTrue($alice->getId()->equals($reloaded->getAuthor()?->getId()));
    }

    public function testCannotCommentOnOtherUsersPersonalTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsTask = $this->createTask($bob, "Bob's secret");

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $bobsTask->getId(),
                'body' => 'Sneaky.',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        // TaskOwnerExtension hides Bob's task from Alice during IRI
        // resolution, so denormalization fails before securityPostDenormalize
        // even runs. 400 is the right shape (the IRI looks malformed from
        // Alice's perspective); the security check never fires because
        // there's no entity to check against.
        $this->assertResponseStatusCodeSame(400);

        // No comment was persisted on Bob's task either way.
        $this->entityManager->clear();
        $this->assertSame(
            0,
            (int) $this->entityManager->createQuery(
                'SELECT COUNT(c) FROM App\Entity\Comment c WHERE c.task = :task',
            )->setParameter('task', $bobsTask)->getSingleScalarResult(),
        );
    }

    public function testProjectMemberCanCommentOnProjectTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Plan launch');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => 'Drafting the announcement.',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
    }

    public function testListFiltersToReadableTasks(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceTask = $this->createTask($alice, 'Alice work');
        $bobsTask = $this->createTask($bob, 'Bob work');

        $this->seedComment($alice, $aliceTask, 'My note.');
        $this->seedComment($bob, $bobsTask, 'Bob note.');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/comments');

        $this->assertResponseIsSuccessful();
        $bodies = array_map(
            fn ($c) => $c['body'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        $this->assertSame(['My note.'], $bodies);
    }

    public function testListByTaskReturnsChronological(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task');

        $first = $this->seedComment($alice, $task, 'First');
        $first->getId(); // touch
        // Use direct setters so the test isn't sleeping for ordering.
        $second = new Comment();
        $second->setTask($task);
        $second->setAuthor($alice);
        $second->setBody('Second');
        $reflection = new \ReflectionClass($second);
        $createdAt = $reflection->getProperty('createdAt');
        $createdAt->setValue($second, $first->getCreatedAt()->modify('+1 minute'));
        $this->entityManager->persist($second);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/comments?task=/tasks/' . $task->getId());

        $this->assertResponseIsSuccessful();
        $bodies = array_map(
            fn ($c) => $c['body'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        $this->assertSame(['First', 'Second'], $bodies);
    }

    public function testEditOwnComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task');
        $comment = $this->seedComment($alice, $task, 'Original');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/comments/' . $comment->getId(), [
            'json' => ['body' => 'Edited'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Comment::class)->find($comment->getId());
        $this->assertSame('Edited', $reloaded->getBody());
        $this->assertNotNull($reloaded->getUpdatedAt(), 'updatedAt should be stamped on edit.');
    }

    public function testCannotEditAnotherUsersComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Task');
        $bobsComment = $this->seedComment($bob, $task, 'Bob said');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/comments/' . $bobsComment->getId(), [
            'json' => ['body' => 'Alice editing!'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAuthorCanDeleteOwnComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task');
        $comment = $this->seedComment($alice, $task, 'Mine');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/comments/' . $comment->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testTaskOwnerCanDeleteAnyCommentOnTheirTask(): void
    {
        // Project lead sweeps up after a teammate — owner of the task
        // should be allowed to remove a comment they didn't author.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Task');
        $bobsComment = $this->seedComment($bob, $task, 'Off-topic');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/comments/' . $bobsComment->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testProjectMemberWhoIsntOwnerCannotDeleteOthersComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Task');
        $aliceComment = $this->seedComment($alice, $task, 'Owner note');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('DELETE', '/comments/' . $aliceComment->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCannotReadCommentOnInaccessibleTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsTask = $this->createTask($bob, "Bob's task");
        $bobsComment = $this->seedComment($bob, $bobsTask, 'Private');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/comments/' . $bobsComment->getId());

        // Extension scopes the query, so cross-task lookups return 404.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testEmptyBodyRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => '',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testReplyToCommentOnSameTaskSucceeds(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task');
        $root = $this->seedComment($alice, $task, 'Root');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'parentComment' => '/comments/' . $root->getId(),
                'body' => 'A reply',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Comment',
            'body' => 'A reply',
            'parentComment' => '/comments/' . $root->getId(),
        ]);
    }

    public function testReplyParentMustBelongToSameTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $taskA = $this->createTask($alice, 'Task A');
        $taskB = $this->createTask($alice, 'Task B');
        $rootOnA = $this->seedComment($alice, $taskA, 'Root on A');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $taskB->getId(),
                'parentComment' => '/comments/' . $rootOnA->getId(),
                'body' => 'Wrong task',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testReplyDepthCapped(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task');
        $depth1 = $this->seedComment($alice, $task, 'd1');
        $depth2 = $this->seedReply($alice, $task, $depth1, 'd2');
        $depth3 = $this->seedReply($alice, $task, $depth2, 'd3');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'parentComment' => '/comments/' . $depth3->getId(),
                'body' => 'too deep',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testDeletingParentCascadesToReplies(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task');
        $root = $this->seedComment($alice, $task, 'Root');
        $reply = $this->seedReply($alice, $task, $root, 'reply');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/comments/' . $root->getId());
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $stillThere = $this->entityManager->getRepository(Comment::class)->find($reply->getId());
        $this->assertNull($stillThere, 'Reply should be removed when its parent is deleted.');
    }

    public function testCanFilterCommentsByParent(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task');
        $root = $this->seedComment($alice, $task, 'Root');
        $this->seedReply($alice, $task, $root, 'reply-1');
        $this->seedReply($alice, $task, $root, 'reply-2');
        $this->seedComment($alice, $task, 'Other root');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/comments?parentComment=/comments/' . $root->getId());

        $this->assertResponseIsSuccessful();
        $bodies = array_map(
            fn ($c) => $c['body'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        sort($bodies);
        $this->assertSame(['reply-1', 'reply-2'], $bodies);
    }

    public function testOversizedBodyRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => str_repeat('x', Comment::MAX_BODY_LENGTH + 1),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testMentionCreatesNotificationForProjectMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Plan launch');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => 'Heads up @bob — can you review?',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(Notification::class)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame(Notification::TYPE_TASK_MENTION, $rows[0]->getType());
        $this->assertSame(
            (string) $bob->getId(),
            (string) $rows[0]->getRecipient()?->getId(),
        );
    }

    public function testMentionOfNonMemberIsIgnored(): void
    {
        $alice = $this->createUser('alice@example.com');
        // Charlie exists but isn't a member of the task's project.
        $charlie = $this->createUser('charlie@example.com');
        $project = $this->createSharedProject($alice, [$alice], 'Solo');
        $task = $this->createTaskInProject($alice, $project, 'Task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => 'cc @charlie',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // Comment itself still posts — unknown @ tokens are plain text,
        // not validation errors.
        $this->assertResponseStatusCodeSame(201);
        $this->assertNotNull($charlie->getId());

        $this->entityManager->clear();
        $this->assertCount(
            0,
            $this->entityManager->getRepository(Notification::class)->findAll(),
        );
    }

    public function testMentionDoesNotNotifyAuthor(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createSharedProject($alice, [$alice], 'Solo');
        $task = $this->createTaskInProject($alice, $project, 'Task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => 'Reminding @alice (me)',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $this->assertCount(
            0,
            $this->entityManager->getRepository(Notification::class)->findAll(),
        );
    }

    public function testEditingCommentDoesNotResendExistingMention(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => 'Hey @bob',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $created = $client->getResponse()->toArray();

        // Edit keeps the same mention — must NOT create a second notification.
        $client->request('PATCH', $created['@id'], [
            'json' => ['body' => 'Hey @bob (small typo fix)'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $this->assertCount(
            1,
            $this->entityManager->getRepository(Notification::class)->findAll(),
        );
    }

    public function testEditingCommentToAddNewMentionFiresOnlyForNewRecipient(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob, $carol], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => 'Hey @bob',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $created = $client->getResponse()->toArray();

        $client->request('PATCH', $created['@id'], [
            'json' => ['body' => 'Hey @bob and @carol'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(Notification::class)->findAll();
        $this->assertCount(2, $rows);
        $emails = array_map(
            fn (Notification $n) => $n->getRecipient()?->getEmail(),
            $rows,
        );
        sort($emails);
        $this->assertSame(['bob@example.com', 'carol@example.com'], $emails);
    }

    public function testDuplicateMentionsInOneCommentNotifyOnce(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => '@bob @BOB @bob — please look',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $this->assertCount(
            1,
            $this->entityManager->getRepository(Notification::class)->findAll(),
        );
    }

    private function seedComment(User $author, Task $task, string $body): Comment
    {
        $c = new Comment();
        $c->setAuthor($author);
        $c->setTask($task);
        $c->setBody($body);
        $this->entityManager->persist($c);
        $this->entityManager->flush();
        return $c;
    }

    private function seedReply(User $author, Task $task, Comment $parent, string $body): Comment
    {
        $c = new Comment();
        $c->setAuthor($author);
        $c->setTask($task);
        $c->setParentComment($parent);
        $c->setBody($body);
        $this->entityManager->persist($c);
        $this->entityManager->flush();
        return $c;
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

    private function createTaskInProject(User $owner, Project $project, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setProject($project);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    /**
     * @param User[] $members
     */
    private function createSharedProject(User $owner, array $members, string $title): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        foreach ($members as $member) {
            $project->addMember($member);
        }
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
    }

    /**
     * @param string[] $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
