<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\MediaObject;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProjectAttachmentTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->storage = $container->get('media.storage');

        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testOwnerCanAttachOwnUpload(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Launch plan', [$alice]);
        $media = $this->createMediaObject($alice);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        // Project response embeds the MediaObject normalised under
        // `attachments` — verify both the kind reaches the client and the
        // gated download URL is returned.
        $this->assertJsonContains([
            'attachments' => [
                [
                    '@type' => 'MediaObject',
                    'kind' => 'attachment',
                    'downloadUrl' => '/media-objects/' . $media->getId() . '/download',
                ],
            ],
        ]);
    }

    public function testMemberCanAttachOwnUpload(): void
    {
        // Project member (not owner) uploads a file and attaches it. The
        // looser ValidProjectAttachments rule lets any member contribute.
        $owner = $this->createUser('owner@example.com');
        $member = $this->createUser('member@example.com');
        $project = $this->createProject($owner, 'Shared', [$owner, $member]);
        $media = $this->createMediaObject($member);

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->reloadProject($project);
        $this->assertCount(1, $project->getAttachments());
    }

    public function testStrangerUploadCannotBeAttached(): void
    {
        // The PATCH succeeds at the auth layer (the caller is a member) but
        // ValidProjectAttachments rejects the IRI — the underlying
        // MediaObject's owner isn't on the project.
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $project = $this->createProject($owner, 'Private', [$owner]);
        $media = $this->createMediaObject($stranger);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testNonMemberCannotPatchProject(): void
    {
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $project = $this->createProject($owner, 'Private', [$owner]);
        $media = $this->createMediaObject($stranger);

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        // Project security expression rejects non-members before validation
        // runs. 404 (not 403) per the same rule the GET uses.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAvatarKindCannotBeAttached(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Launch', [$alice]);
        $avatar = $this->createMediaObject($alice, MediaObject::KIND_AVATAR);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $avatar->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testProjectMemberCanDownloadAttachment(): void
    {
        // Cross-cuts MediaObjectDownloadController: attaching to a project
        // should grant download access to every member, even ones who
        // didn't upload it themselves.
        $owner = $this->createUser('owner@example.com');
        $member = $this->createUser('member@example.com');
        $project = $this->createProject($owner, 'Shared', [$owner, $member]);
        $media = $this->createMediaObject($owner);
        $project->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');

        $this->assertResponseIsSuccessful();
    }

    public function testStrangerCannotDownloadProjectAttachment(): void
    {
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $project = $this->createProject($owner, 'Private', [$owner]);
        $media = $this->createMediaObject($owner);
        $project->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');

        // Same rule as task attachments: 404 not 403, no enumeration.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDetachKeepsMediaObjectRow(): void
    {
        // Removing an attachment from the join table must not delete the
        // underlying MediaObject — orphan cleanup is a separate ticket.
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Launch', [$alice]);
        $media = $this->createMediaObject($alice);
        $project->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['attachments' => []],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloadedMedia = $this->entityManager->getRepository(MediaObject::class)->find($media->getId());
        $this->assertNotNull($reloadedMedia, 'Detach must not delete the MediaObject row.');
        $reloadedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        $this->assertCount(0, $reloadedProject->getAttachments());
    }

    private function createMediaObject(User $owner, string $kind = MediaObject::KIND_ATTACHMENT): MediaObject
    {
        $bytes = 'PROJECT-FILE';
        $path = 'attachments/proj-test-' . bin2hex(random_bytes(4)) . '-spec.pdf';
        $this->storage->write($path, $bytes);

        $media = new MediaObject();
        $media->setOwner($owner);
        $media->setKind($kind);
        $media->setVariants(['original' => $path]);
        $media->setOriginalName('spec.pdf');
        $media->setMimeType('application/pdf');
        $media->setByteSize(\strlen($bytes));
        $this->entityManager->persist($media);
        $this->entityManager->flush();
        return $media;
    }

    /**
     * @param User[] $members
     */
    private function createProject(User $owner, string $title, array $members): Project
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

    private function reloadProject(Project &$project): void
    {
        $id = $project->getId();
        $this->entityManager->clear();
        $project = $this->entityManager->getRepository(Project::class)->find($id);
    }
}
