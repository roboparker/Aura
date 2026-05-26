<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\MediaObject;
use App\Entity\Task;
use App\Entity\User;
use App\Service\ImageUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TaskAttachmentTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Wipe in dependency-safe order; the join table is cleaned up via
        // the FK cascades when its sides go.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testUploadAttachmentReturnsAttachmentKindMediaObject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $upload = $this->makeUploadedFile('notes.txt', "Hello, world.\n", 'text/plain');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/media-objects', [
            'extra' => [
                'parameters' => ['kind' => MediaObject::KIND_ATTACHMENT],
                'files' => ['file' => $upload],
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame(MediaObject::KIND_ATTACHMENT, $body['kind']);
        $this->assertSame('notes.txt', $body['originalName']);
        $this->assertSame('text/plain', $body['mimeType']);
        $variantUrls = $body['variantUrls'] ?? null;
        $this->assertIsArray($variantUrls);
        $this->assertArrayHasKey('original', $variantUrls);
        $original = $variantUrls['original'];
        $this->assertIsString($original);
        $this->assertStringStartsWith('/media/attachments/', $original);
    }

    public function testUploadAttachmentRejectsDisallowedMime(): void
    {
        $alice = $this->createUser('alice@example.com');
        $upload = $this->makeUploadedFile('exec.sh', "#!/bin/sh\necho hi\n", 'application/x-sh');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/media-objects', [
            'extra' => [
                'parameters' => ['kind' => MediaObject::KIND_ATTACHMENT],
                'files' => ['file' => $upload],
            ],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUploadAttachmentRejectsOversizedFile(): void
    {
        // The HTTP path is awkward to exercise with a 10MB+ payload via the
        // test client (the temp file is recycled between request setup and
        // the processor and its mime guess fails noisily). The size check
        // is plain branch logic in the service, so it's enough to assert
        // that path directly.
        static::bootKernel();
        $alice = $this->createUser('alice@example.com');
        $oversized = str_repeat('x', ImageUploadService::MAX_ATTACHMENT_BYTES + 1);
        $upload = $this->makeUploadedFile('big.txt', $oversized, 'text/plain');

        /** @var ImageUploadService $service */
        $service = static::getContainer()->get(ImageUploadService::class);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/larger than/');
        $service->uploadAttachment($upload, $alice);
    }

    public function testAttachMediaToOwnTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $media = $this->seedAttachment($alice, 'spec.pdf');
        $task = $this->createTask($alice, 'Review spec');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $attachments = $body['attachments'] ?? null;
        $this->assertIsArray($attachments);
        $this->assertCount(1, $attachments);
        $first = $attachments[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('spec.pdf', $first['originalName']);
        $variantUrls = $first['variantUrls'] ?? null;
        $this->assertIsArray($variantUrls);
        $this->assertArrayHasKey('original', $variantUrls);
    }

    public function testAttachMediaPersistsAndAppearsOnGet(): void
    {
        $alice = $this->createUser('alice@example.com');
        $media = $this->seedAttachment($alice, 'spec.pdf');
        $task = $this->createTask($alice, 'Review spec');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/tasks/' . $task->getId());
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $attachments = $body['attachments'] ?? null;
        $this->assertIsArray($attachments);
        $this->assertCount(1, $attachments);
    }

    public function testCannotAttachAnotherUsersMedia(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsMedia = $this->seedAttachment($bob, 'bob-secret.pdf');
        $aliceTask = $this->createTask($alice, 'Alice work');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $aliceTask->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $bobsMedia->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testCannotAttachAvatarKindMedia(): void
    {
        $alice = $this->createUser('alice@example.com');
        $avatar = $this->seedAvatar($alice);
        $task = $this->createTask($alice, 'My task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['attachments' => ['/media-objects/' . $avatar->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRemoveAttachmentByPatchWithoutIt(): void
    {
        $alice = $this->createUser('alice@example.com');
        $media = $this->seedAttachment($alice, 'spec.pdf');
        $task = $this->createTask($alice, 'Task');
        $task->addAttachment($media);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['attachments' => []],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(0, $reloaded->getAttachments());
    }

    private function makeUploadedFile(string $name, string $contents, string $mime): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upload-');
        if (false === $tmp) {
            $this->fail('Could not create temp file for upload.');
        }
        file_put_contents($tmp, $contents);
        // `test: true` skips the is_uploaded_file() check; we need that
        // because Symfony's UploadedFile is normally only constructed by
        // the HttpFoundation request layer.
        return new UploadedFile($tmp, $name, $mime, null, true);
    }

    private function seedAttachment(User $owner, string $name): MediaObject
    {
        $media = new MediaObject();
        $media->setOwner($owner);
        $media->setKind(MediaObject::KIND_ATTACHMENT);
        $media->setVariants(['original' => 'attachments/seeded-' . $name]);
        $media->setOriginalName($name);
        $media->setMimeType('application/pdf');
        $media->setByteSize(1024);
        $this->entityManager->persist($media);
        $this->entityManager->flush();
        return $media;
    }

    private function seedAvatar(User $owner): MediaObject
    {
        $media = new MediaObject();
        $media->setOwner($owner);
        $media->setKind(MediaObject::KIND_AVATAR);
        $media->setVariants(['profile' => 'avatars/x-profile.webp', 'thumb' => 'avatars/x-thumb.webp']);
        $media->setOriginalName('avatar');
        $media->setMimeType('image/webp');
        $media->setByteSize(2048);
        $this->entityManager->persist($media);
        $this->entityManager->flush();
        return $media;
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
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
