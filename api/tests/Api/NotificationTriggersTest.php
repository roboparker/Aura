<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Comment;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The notification platform's event triggers (#…): comment / reply /
 * assigned / status rows created server-side as activity happens, the
 * mention > reply > comment precedence, and the bulk + archive surface.
 */
class NotificationTriggersTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCommentNotifiesTaskOwner(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Plan launch');

        $this->postComment(static::createClient(), $bob, ['task' => '/tasks/' . $task->getId(), 'body' => 'Looks good.']);

        $rows = $this->notificationsFor($alice);
        $this->assertCount(1, $rows);
        $this->assertSame(Notification::TYPE_COMMENT, $rows[0]->getType());
        $this->assertSame((string) $bob->getId(), (string) $rows[0]->getActor()?->getId());
    }

    public function testRealtimeEmailSentForNotification(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Plan launch');

        // Comment emails are off by default in the matrix — opt alice in so we
        // exercise the realtime-email path.
        $prefs = $alice->getPreferences();
        $prefs['notificationMatrix']['comments']['email'] = true;
        $alice->setPreferences($prefs);
        $this->entityManager->flush();

        // bob comments → alice (owner, realtime cadence) is emailed.
        $this->postComment(static::createClient(), $bob, ['task' => '/tasks/' . $task->getId(), 'body' => 'Looks good.']);

        $this->assertEmailCount(1);
        $message = $this->getMailerMessage(0);
        self::assertNotNull($message);
        $this->assertEmailHeaderSame($message, 'To', 'alice@example.com');
        $this->assertEmailHtmlBodyContains($message, 'Plan launch');
        $this->assertEmailTextBodyContains($message, 'Plan launch');
    }

    public function testRealtimeEmailSkippedForDigestUser(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $alice->setPreferences(['notificationFrequency' => 'daily']);
        $this->entityManager->flush();
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Plan launch');

        $this->postComment(static::createClient(), $bob, ['task' => '/tasks/' . $task->getId(), 'body' => 'Looks good.']);

        // Digest-cadence users get the in-app row but no realtime email —
        // the digest command owns their delivery.
        $this->assertEmailCount(0);
        $this->assertCount(1, $this->notificationsFor($alice));
    }

    public function testReplyNotifiesPriorParticipant(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob, $carol], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Plan launch');

        $client = static::createClient();
        $this->postComment($client, $bob, ['task' => '/tasks/' . $task->getId(), 'body' => 'First.']);
        $this->postComment($client, $carol, ['task' => '/tasks/' . $task->getId(), 'body' => 'Building on that.']);

        $bobRows = $this->notificationsFor($bob);
        $this->assertCount(1, $bobRows);
        $this->assertSame(Notification::TYPE_REPLY, $bobRows[0]->getType());
        $this->assertSame((string) $carol->getId(), (string) $bobRows[0]->getActor()?->getId());
    }

    public function testMentionWinsOverReply(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob, $carol], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Plan launch');

        // bob comments first (so he's a prior participant), then carol
        // both replies-to-thread AND @mentions bob — mention must win.
        $client = static::createClient();
        $this->postComment($client, $bob, ['task' => '/tasks/' . $task->getId(), 'body' => 'First.']);
        $this->postComment($client, $carol, ['task' => '/tasks/' . $task->getId(), 'body' => 'Good point @bob']);

        $bobRows = $this->notificationsFor($bob);
        $this->assertCount(1, $bobRows, 'bob should get exactly one row for carol\'s comment.');
        $this->assertSame(Notification::TYPE_MENTION, $bobRows[0]->getType());
    }

    public function testAssignedOnTaskCreate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Draft the brief',
                'project' => '/projects/' . $project->getId(),
                'assignees' => ['/users/' . $bob->getId()],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $rows = $this->notificationsFor($bob);
        $this->assertCount(1, $rows);
        $this->assertSame(Notification::TYPE_ASSIGNED, $rows[0]->getType());
        $this->assertSame((string) $alice->getId(), (string) $rows[0]->getActor()?->getId());
    }

    public function testAssignedOnPatchOnlyFiresForNewAssignee(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob, $carol], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Plan launch');
        $task->addAssignee($bob);
        $this->entityManager->flush();

        // Patch to [bob, carol] — only carol is newly assigned.
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => [
                'assignees' => ['/users/' . $bob->getId(), '/users/' . $carol->getId()],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertCount(0, $this->notificationsFor($bob), 'bob was already assigned.');
        $carolRows = $this->notificationsFor($carol);
        $this->assertCount(1, $carolRows);
        $this->assertSame(Notification::TYPE_ASSIGNED, $carolRows[0]->getType());
    }

    public function testStatusOnCompletion(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Ship it');
        $task->addAssignee($bob);
        $this->entityManager->flush();

        // alice completes — bob (the other stakeholder) is notified.
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => '2026-06-05T10:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $bobRows = $this->notificationsFor($bob);
        $this->assertCount(1, $bobRows);
        $this->assertSame(Notification::TYPE_STATUS, $bobRows[0]->getType());
        $this->assertSame((string) $alice->getId(), (string) $bobRows[0]->getActor()?->getId());
        // No self-notification for the actor.
        $this->assertCount(0, $this->notificationsFor($alice));
    }

    public function testActorAndTargetSerialized(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Acme');
        $task = $this->createTaskInProject($alice, $project, 'Final hero selects');

        $client = static::createClient();
        $this->postComment($client, $bob, ['task' => '/tasks/' . $task->getId(), 'body' => 'Pulled my top three.']);

        $client->loginUser($alice);
        $client->request('GET', '/notifications');
        $this->assertResponseIsSuccessful();

        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? [];
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $row = $members[0];
        $this->assertIsArray($row);

        $this->assertSame('/tasks', $row['targetUrl'] ?? null);
        $this->assertSame('Final hero selects', $row['targetLabel'] ?? null);
        $this->assertSame('Acme', $row['contextLabel'] ?? null);
        $actor = $row['actor'] ?? null;
        $this->assertIsArray($actor);
        $this->assertSame('bob@example.com', $actor['email'] ?? null);
    }

    public function testBulkMarkReadAndArchiveAreRecipientScoped(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceNote = $this->seedNotification($alice);
        $bobNote = $this->seedNotification($bob);

        $client = static::createClient();
        $client->loginUser($alice);

        // Mark all read — only alice's row flips.
        $client->request('POST', '/notifications/mark-read', [
            'json' => ['all' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Archive alice's row by id; passing bob's id is a silent no-op.
        $client->request('POST', '/notifications/archive', [
            'json' => ['ids' => [(string) $aliceNote->getId(), (string) $bobNote->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloadedAlice = $this->entityManager->getRepository(Notification::class)->find($aliceNote->getId());
        $reloadedBob = $this->entityManager->getRepository(Notification::class)->find($bobNote->getId());
        self::assertNotNull($reloadedAlice);
        self::assertNotNull($reloadedBob);
        $this->assertNotNull($reloadedAlice->getReadAt());
        $this->assertNotNull($reloadedAlice->getArchivedAt());
        $this->assertNull($reloadedBob->getReadAt(), "bob's notification must be untouched.");
        $this->assertNull($reloadedBob->getArchivedAt(), "bob's notification must be untouched.");
    }

    /**
     * @return Notification[]
     */
    private function notificationsFor(User $user): array
    {
        $this->entityManager->clear();
        $fresh = $this->entityManager->getRepository(User::class)->find($user->getId());

        return $this->entityManager->getRepository(Notification::class)->findBy(['recipient' => $fresh]);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function postComment(Client $client, User $author, array $json): void
    {
        $client->loginUser($author);
        $client->request('POST', '/comments', [
            'json' => $json,
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    private function seedNotification(User $recipient): Notification
    {
        $note = new Notification();
        $note->setRecipient($recipient);
        $note->setType(Notification::TYPE_COMMENT);
        $note->setTitle('Something happened');
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $note;
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
            $this->addProjectMember($project, $member);
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
