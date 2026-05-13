<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\MediaObject;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SpaceAttachmentTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->storage = $container->get('media.storage');

        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAdminCanAttachOwnUpload(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Launch plan');
        $media = $this->createMediaObject($alice);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/spaces/' . $space->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
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

    public function testMemberUploadCannotBeAttachedByAdmin(): void
    {
        // Looser-than-task rule: every member of the space may attach
        // anything *they* uploaded, but not (in v1) someone else's
        // upload. Admin patching with a non-admin member's upload
        // works because the member is in the space.
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, 'Shared');
        $this->addMember($space, $member);
        $media = $this->createMediaObject($member);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('PATCH', '/spaces/' . $space->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->reloadSpace($space);
        $this->assertCount(1, $space->getAttachments());
    }

    public function testStrangerUploadCannotBeAttached(): void
    {
        $admin = $this->createUser('admin@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($admin, 'Private');
        $media = $this->createMediaObject($stranger);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('PATCH', '/spaces/' . $space->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testNonAdminCannotPatchSpace(): void
    {
        // Space PATCH is admin-only — only admins can edit the
        // attachments collection.
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, 'Shared');
        $this->addMember($space, $member);
        $media = $this->createMediaObject($member);

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('PATCH', '/spaces/' . $space->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAvatarKindCannotBeAttached(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Launch');
        $avatar = $this->createMediaObject($alice, MediaObject::KIND_AVATAR);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/spaces/' . $space->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $avatar->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testSpaceMemberCanDownloadAttachment(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, 'Shared');
        $this->addMember($space, $member);
        $media = $this->createMediaObject($admin);
        $space->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');

        $this->assertResponseIsSuccessful();
    }

    public function testStrangerCannotDownloadSpaceAttachment(): void
    {
        $admin = $this->createUser('admin@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($admin, 'Private');
        $media = $this->createMediaObject($admin);
        $space->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDetachKeepsMediaObjectRow(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Launch');
        $media = $this->createMediaObject($alice);
        $space->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/spaces/' . $space->getId(), [
            'json' => ['attachments' => []],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloadedMedia = $this->entityManager->getRepository(MediaObject::class)->find($media->getId());
        $this->assertNotNull($reloadedMedia, 'Detach must not delete the MediaObject row.');
        $reloadedSpace = $this->entityManager->getRepository(Space::class)->find($space->getId());
        $this->assertCount(0, $reloadedSpace->getAttachments());
    }

    private function createMediaObject(User $owner, string $kind = MediaObject::KIND_ATTACHMENT): MediaObject
    {
        $bytes = 'SPACE-FILE';
        $path = 'attachments/space-test-' . bin2hex(random_bytes(4)) . '-spec.pdf';
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

    private function createSpace(User $admin, string $name): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $membership = (new SpaceMembership())
            ->setUser($admin)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($membership);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
        return $space;
    }

    private function addMember(Space $space, User $user, string $role = Space::ROLE_MEMBER): void
    {
        $membership = (new SpaceMembership())
            ->setUser($user)
            ->setRole($role);
        $space->addUserMembership($membership);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
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

    private function reloadSpace(Space &$space): void
    {
        $id = $space->getId();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Space::class)->find($id);
        self::assertNotNull($reloaded, 'Reloaded space disappeared between clear() and find().');
        $space = $reloaded;
    }
}
