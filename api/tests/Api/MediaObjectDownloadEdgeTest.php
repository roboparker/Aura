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

/**
 * Extra branch coverage for {@see \App\Controller\MediaObjectDownloadController}
 * that MediaObjectDownloadTest doesn't reach: the space-attachment
 * readability arm (member downloads, non-member 404) and the
 * no-variant-path 404 fallback.
 */
class MediaObjectDownloadEdgeTest extends ApiTestCase
{
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

        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testSpaceMemberCanDownloadSpaceAttachment(): void
    {
        $owner = $this->createUser('owner@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($owner, [$member]);

        // Uploaded by the owner, attached to the space; a different member
        // who didn't upload it can still download via space membership.
        $media = $this->seedAttachment($owner, 'shared.pdf', 'SHAREDBYTES');
        $space->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');
        $this->assertResponseIsSuccessful();
    }

    public function testNonSpaceMemberCannotDownloadSpaceAttachment(): void
    {
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($owner, []);

        $media = $this->seedAttachment($owner, 'secret.pdf', 'SECRET');
        $space->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');
        // 404 (not 403) so the endpoint can't enumerate MediaObject ids.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testMediaWithoutVariantPathReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');

        // Owner has access, but the MediaObject has no stored variant, so
        // the controller can't resolve a path to stream → 404.
        $media = new MediaObject();
        $media->setOwner($alice);
        $media->setKind(MediaObject::KIND_ATTACHMENT);
        $media->setVariants([]);
        $media->setOriginalName('empty.pdf');
        $media->setMimeType('application/pdf');
        $media->setByteSize(0);
        $this->entityManager->persist($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/media-objects/' . $media->getId() . '/download');
        $this->assertResponseStatusCodeSame(404);
    }

    private function seedAttachment(User $owner, string $name, string $bytes): MediaObject
    {
        $path = 'attachments/edge-' . bin2hex(random_bytes(4)) . '-' . $name;
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

    /**
     * @param User[] $extraMembers
     */
    private function createSpace(User $owner, array $extraMembers): Space
    {
        $space = new Space();
        $space->setName('Shared space');
        $space->setCreatedBy($owner);
        $this->entityManager->persist($space);

        $admin = (new SpaceMembership())
            ->setUser($owner)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($admin);
        $this->entityManager->persist($admin);

        foreach ($extraMembers as $m) {
            $membership = (new SpaceMembership())
                ->setUser($m)
                ->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($membership);
            $this->entityManager->persist($membership);
        }

        $this->entityManager->flush();
        return $space;
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
