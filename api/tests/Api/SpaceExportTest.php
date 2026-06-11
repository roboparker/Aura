<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Comment;
use App\Entity\Discussion;
use App\Entity\Page;
use App\Entity\Project;
use App\Entity\MediaObject;
use App\Entity\Space;
use App\Entity\SpaceExport;
use App\Entity\SpaceMembership;
use App\Entity\Task;
use App\Entity\User;
use App\Service\SpaceExportPruner;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Space data export (#space-export): request gating, the async build (the
 * test transport is sync://, so the zip is built and the email sent inside
 * the POST), the token-gated download, and the retention prune.
 */
class SpaceExportTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->storage = $container->get('media.storage');

        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Page')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Discussion')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceExport')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRequiresAuth(): void
    {
        static::createClient()->request('POST', '/spaces/' . str_repeat('0', 36) . '/export', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminRequestsExportAndDownloadsViaEmailedLink(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Team Rocket');
        $project = $this->createProject($alice, 'Launch plan', $space);
        $task = $this->createTask($alice, $project, 'Book the venue');
        $this->createComment($alice, $task, 'Deposit paid.');
        $this->createDiscussion($alice, $space, 'Kickoff thread');
        $this->createPage($alice, $space, 'Runbook');

        // A space attachment exercises the streamed addFile() path.
        $media = $this->createMediaObject($alice, 'CONTRACT-BYTES', 'contract.pdf');
        $space->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/export', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);

        // sync:// transport: the zip was built and the email sent inline.
        $this->assertEmailCount(1);
        $message = $this->getMailerMessage(0);
        self::assertNotNull($message);
        $this->assertEmailHeaderSame($message, 'To', 'alice@example.com');
        $this->assertEmailTextBodyContains($message, 'Team Rocket');
        $token = $this->extractToken($message);

        $this->entityManager->clear();
        $export = $this->entityManager->getRepository(SpaceExport::class)->findOneBy([]);
        $this->assertNotNull($export);
        $this->assertSame(SpaceExport::STATUS_COMPLETED, $export->getStatus());
        $this->assertNotNull($export->getExpiresAt());
        $this->assertNotNull($export->getCompletedAt());
        // Default retention: link lives 7 days from completion.
        $this->assertSame(
            $export->getCompletedAt()->add(new \DateInterval('P7D'))->format('Y-m-d'),
            $export->getExpiresAt()->format('Y-m-d'),
        );

        $path = $export->getFilePath();
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertZipContains($path, 'space.json', 'Team Rocket');
        $this->assertZipContains($path, 'projects.json', 'Launch plan');
        $this->assertZipContains($path, 'tasks.json', 'Book the venue');
        $this->assertZipContains($path, 'tasks.json', 'Deposit paid.');
        $this->assertZipContains($path, 'discussions.json', 'Kickoff thread');
        $this->assertZipContains($path, 'pages.json', 'Runbook');
        $this->assertZipContains(
            $path,
            'attachments/' . $media->getId() . '-contract.pdf',
            'CONTRACT-BYTES',
        );

        // Status endpoint says ready…
        $client->request('GET', '/space-exports/' . $token);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'ready', 'spaceName' => 'Team Rocket']);

        // …and the download serves the archive.
        $client->request('GET', '/space-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('content-type', 'application/zip');
    }

    public function testMemberCannotRequestExport(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/spaces/' . $space->getId() . '/export', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testNonMemberCannotSeeSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $mallory = $this->createUser('mallory@example.com');
        $space = $this->createSpace($alice, 'Hidden');

        $client = static::createClient();
        $client->loginUser($mallory);
        $client->request('POST', '/spaces/' . $space->getId() . '/export', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDownloadIsRestrictedToSpaceAdmins(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $mallory = $this->createUser('mallory@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($space, $bob, Space::ROLE_ADMIN);
        $this->ensureSpaceMembership($space, $carol, Space::ROLE_MEMBER);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/export', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $message = $this->getMailerMessage(0);
        self::assertNotNull($message);
        $token = $this->extractToken($message);

        // A co-admin of the space can use the link (download bar mirrors
        // the space-admin request bar).
        $client->loginUser($bob);
        $client->request('GET', '/space-exports/' . $token);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'ready']);
        $client->request('GET', '/space-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(200);

        // A plain member of the space cannot.
        $client->loginUser($carol);
        $client->request('GET', '/space-exports/' . $token);
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/space-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(404);

        // A non-member cannot.
        $client->loginUser($mallory);
        $client->request('GET', '/space-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(404);

        // Signed out, the link is useless.
        $anonymous = static::createClient();
        $anonymous->request('GET', '/space-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testExpiredExportReportsExpiredAndBlocksDownload(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/export', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $message = $this->getMailerMessage(0);
        self::assertNotNull($message);
        $token = $this->extractToken($message);

        $this->entityManager->clear();
        $export = $this->entityManager->getRepository(SpaceExport::class)->findOneBy([]);
        $this->assertNotNull($export);
        $export->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();

        $client->request('GET', '/space-exports/' . $token);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'expired']);

        $client->request('GET', '/space-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSecondRequestWhileInFlightConflicts(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');

        // Simulate a queued-but-unprocessed export (in prod the worker
        // hasn't picked it up yet; under sync:// we have to seed it).
        $pending = new SpaceExport($space, $alice);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/export', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testPrunerDeletesExpiredExportsAndFiles(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Shared');

        $expiredFile = tempnam(sys_get_temp_dir(), 'aura-export-test-');
        $this->assertIsString($expiredFile);

        $expired = new SpaceExport($space, $alice);
        $expired->setStatus(SpaceExport::STATUS_COMPLETED);
        $expired->setFilePath($expiredFile);
        $expired->setTokenHash(hash('sha256', 'expired-token'));
        $expired->setCompletedAt(new \DateTimeImmutable('-10 days'));
        $expired->setExpiresAt(new \DateTimeImmutable('-3 days'));
        $this->entityManager->persist($expired);

        $fresh = new SpaceExport($space, $alice);
        $fresh->setStatus(SpaceExport::STATUS_COMPLETED);
        $fresh->setTokenHash(hash('sha256', 'fresh-token'));
        $fresh->setCompletedAt(new \DateTimeImmutable('-1 day'));
        $fresh->setExpiresAt(new \DateTimeImmutable('+6 days'));
        $this->entityManager->persist($fresh);

        $stalePending = new SpaceExport($space, $alice);
        $this->entityManager->persist($stalePending);
        $this->entityManager->flush();

        // Backdate the stuck pending row past the retention window.
        $this->entityManager->createQuery(
            'UPDATE App\Entity\SpaceExport e SET e.createdAt = :old WHERE e.id = :id',
        )
            ->setParameter('old', new \DateTimeImmutable('-30 days'))
            ->setParameter('id', $stalePending->getId())
            ->execute();
        $this->entityManager->clear();

        $pruner = static::getContainer()->get(SpaceExportPruner::class);
        $deleted = $pruner->prune();

        $this->assertSame(2, $deleted);
        $this->assertFileDoesNotExist($expiredFile);

        $remaining = $this->entityManager->getRepository(SpaceExport::class)->findAll();
        $this->assertCount(1, $remaining);
        $this->assertSame((string) $fresh->getId(), (string) $remaining[0]->getId());
    }

    private function extractToken(RawMessage $message): string
    {
        $this->assertInstanceOf(Email::class, $message);
        $text = $message->getTextBody();
        $this->assertIsString($text);
        if (1 !== preg_match('#/exports/([0-9a-f]{64})#', $text, $matches)) {
            $this->fail('Export email did not contain a download link.');
        }

        return $matches[1];
    }

    private function assertZipContains(string $zipPath, string $entry, string $needle): void
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::RDONLY));
        $contents = $zip->getFromName($entry);
        $zip->close();
        $this->assertIsString($contents, sprintf('Archive entry "%s" is missing.', $entry));
        $this->assertStringContainsString($needle, $contents);
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

    private function createProject(User $owner, string $title, Space $space): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        $project->setSpace($space);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
    }

    private function createTask(User $owner, Project $project, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        $task->setProject($project);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    private function createComment(User $author, Task $task, string $body): Comment
    {
        $comment = (new Comment())
            ->setAuthor($author)
            ->setTask($task)
            ->setBody($body);
        $this->entityManager->persist($comment);
        $this->entityManager->flush();
        return $comment;
    }

    private function createDiscussion(User $author, Space $space, string $title): Discussion
    {
        $discussion = (new Discussion())
            ->setAuthor($author)
            ->setSpace($space)
            ->setTitle($title)
            ->setBody('Discussion body.');
        $this->entityManager->persist($discussion);
        $this->entityManager->flush();
        return $discussion;
    }

    private function createPage(User $author, Space $space, string $title): Page
    {
        $page = (new Page())
            ->setCreatedBy($author)
            ->setSpace($space)
            ->setTitle($title)
            ->setBody('Page body.');
        $this->entityManager->persist($page);
        $this->entityManager->flush();
        return $page;
    }

    private function createMediaObject(User $owner, string $bytes, string $name): MediaObject
    {
        $path = 'attachments/export-test-' . bin2hex(random_bytes(4)) . '-' . $name;
        $this->storage->write($path, $bytes);

        $media = new MediaObject();
        $media->setOwner($owner);
        $media->setKind(MediaObject::KIND_ATTACHMENT);
        $media->setVariants(['original' => $path]);
        $media->setOriginalName($name);
        $media->setMimeType('application/pdf');
        $media->setByteSize(\strlen($bytes));
        $this->entityManager->persist($media);
        $this->entityManager->flush();
        return $media;
    }

    private function createUser(string $email): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

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
