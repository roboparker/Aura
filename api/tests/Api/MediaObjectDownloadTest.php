<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\MediaObject;
use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class MediaObjectDownloadTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        // `static::getContainer()` returns the test-only container that
        // exposes private services; `$kernel->getContainer()` is the
        // production container, which has `media.storage` compiled away.
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->storage = $container->get('media.storage');

        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $media = $this->seedAttachment($alice, 'spec.pdf', "PDFBYTES");

        $client = static::createClient();
        // No loginUser — anonymous request.
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');
        // The endpoint returns 401 explicitly when no user is attached, but
        // some test configs short-circuit anonymous requests at the firewall
        // before reaching the controller; either way, 200 must not happen.
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function testOwnerCanDownloadOwnAttachment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $media = $this->seedAttachment($alice, 'spec.pdf', "PDFBYTES\n");

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        // HeaderUtils::makeDisposition produces `attachment; filename=spec.pdf`
        // for ASCII names. Matching the literal makes the test fail loudly if
        // the controller ever drops attachment-mode or the filename.
        $this->assertResponseHeaderSame(
            'Content-Disposition',
            'attachment; filename=spec.pdf',
        );
        // Content-Length is taken from MediaObject::byteSize, so this also
        // sanity-checks that the stored size matches what we wrote — and
        // serves as a proxy for "the right MediaObject was looked up" without
        // having to drain the StreamedResponse callback (which the test
        // client never invokes; sendContent() is a no-op until the kernel
        // would flush to the wire).
        $this->assertResponseHeaderSame(
            'Content-Length',
            (string) strlen("PDFBYTES\n"),
        );
    }

    public function testTaskOwnerCanDownloadAttachmentTheyDidntUpload(): void
    {
        // Board workflow: Bob uploads a file, attaches it to a task, and
        // Alice (board teammate / task owner) needs to download it.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $board = $this->createProject($alice, [$bob]);

        $media = $this->seedAttachment($bob, 'shared.pdf', "SHARED");
        $task = $this->createTask($alice, 'Board task', $board);
        $task->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');
        $this->assertResponseIsSuccessful();
    }

    public function testProjectMemberCanDownloadAttachmentOnSharedTask(): void
    {
        $owner = $this->createUser('owner@example.com');
        $member = $this->createUser('member@example.com');
        $board = $this->createProject($owner, [$member]);

        $media = $this->seedAttachment($owner, 'team.pdf', "TEAM");
        $task = $this->createTask($owner, 'Board task', $board);
        $task->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');
        $this->assertResponseIsSuccessful();
    }

    public function testStrangerCannotDownload(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $media = $this->seedAttachment($alice, 'private.pdf', "PRIVATE");
        $task = $this->createTask($alice, 'Personal task');
        $task->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');
        // 404 — not 403 — so the endpoint can't be used to enumerate IDs.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUserWithOwnProjectTaskCannotDownloadForeignAttachment(): void
    {
        // Regression for the gated-download authorization OR-clause: it must
        // be parenthesized. Before the fix the query read
        //   (:media MEMBER OF t.attachments AND t.owner = :user)
        //     OR EXISTS(space-membership) OR EXISTS(group-membership)
        // so the attachment check only gated the owner branch. Any user who
        // owned even one board-bound task in a space they belong to (the
        // normal steady state) satisfied a membership EXISTS for *any* media
        // id and could download another tenant's attachment.
        $attacker = $this->createUser('attacker@example.com');
        $victim = $this->createUser('victim@example.com');

        // The precondition that made a membership EXISTS branch true: the
        // attacker owns a task attached to a board whose space they're in.
        $attackerProject = $this->createProject($attacker);
        $this->createTask($attacker, 'Attacker task', $attackerProject);

        // The victim's private attachment lives on a task in the victim's own
        // space — the attacker is not a member of it.
        $victimProject = $this->createProject($victim);
        $media = $this->seedAttachment($victim, 'secret.pdf', 'TOPSECRET');
        $victimTask = $this->createTask($victim, 'Victim task', $victimProject);
        $victimTask->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($attacker);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');
        // 404 — the attacker has no relationship to this media at all.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownMediaIdReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        // Valid UUID format but no row in the DB.
        $client->request('GET', '/media-objects/01919999-9999-7999-9999-999999999999/download');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidIdReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/media-objects/not-a-uuid/download');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testOrphanAttachmentDownloadableByOwner(): void
    {
        // Owner can download even before attaching to a task — the upload
        // flow is two-step (POST media, then PATCH task) and the panel
        // shouldn't 404 in between.
        $alice = $this->createUser('alice@example.com');
        $media = $this->seedAttachment($alice, 'draft.pdf', "DRAFT");

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');
        $this->assertResponseIsSuccessful();
    }

    private function seedAttachment(User $owner, string $name, string $bytes): MediaObject
    {
        $path = 'attachments/seeded-' . bin2hex(random_bytes(4)) . '-' . $name;
        $this->storage->write($path, $bytes);

        $media = new MediaObject();
        $media->setOwner($owner);
        $media->setKind(MediaObject::KIND_ATTACHMENT);
        $media->setVariants(['original' => $path]);
        $media->setOriginalName($name);
        $media->setMimeType('application/pdf');
        $media->setByteSize(strlen($bytes));
        $this->entityManager->persist($media);
        $this->entityManager->flush();
        return $media;
    }

    private function createTask(User $owner, string $title, ?Board $board = null): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        if (null !== $board) {
            $task->setBoard($board);
        }
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    /**
     * @param User[] $extraMembers
     */
    private function createProject(User $owner, array $extraMembers = []): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle('Test board');
        $this->addBoardMember($board, $owner);
        foreach ($extraMembers as $m) {
            $this->addBoardMember($board, $m);
        }
        $this->entityManager->persist($board);
        $this->entityManager->flush();
        return $board;
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
