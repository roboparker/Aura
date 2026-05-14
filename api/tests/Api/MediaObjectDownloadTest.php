<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\MediaObject;
use App\Entity\Project;
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
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
        // Project workflow: Bob uploads a file, attaches it to a task, and
        // Alice (project teammate / task owner) needs to download it.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, [$bob]);

        $media = $this->seedAttachment($bob, 'shared.pdf', "SHARED");
        $task = $this->createTask($alice, 'Project task', $project);
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
        $project = $this->createProject($owner, [$member]);

        $media = $this->seedAttachment($owner, 'team.pdf', "TEAM");
        $task = $this->createTask($owner, 'Project task', $project);
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

    /**
     * @param User[] $extraMembers
     */
    private function createProject(User $owner, array $extraMembers = []): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle('Test project');
        $this->addProjectMember($project, $owner);
        foreach ($extraMembers as $m) {
            $this->addProjectMember($project, $m);
        }
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
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
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
