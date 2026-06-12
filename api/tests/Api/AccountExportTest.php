<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\AccountExport;
use App\Entity\MediaObject;
use App\Entity\Task;
use App\Entity\User;
use App\Service\AccountExportPruner;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * GDPR account data export (#account-export): the step-up-gated request, the
 * async build (the test transport is sync://, so the zip is built and the
 * email sent inside the POST), the token-gated download restricted to the
 * requester, and the retention prune.
 */
class AccountExportTest extends ApiTestCase
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

        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\AccountExport')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRequiresAuth(): void
    {
        static::createClient()->request('POST', '/me/export', [
            'json' => ['currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testRequestExportAndDownloadViaEmailedLink(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createTask($alice, 'Pack the boxes');

        // An uploaded file exercises the streamed addFile() path.
        $media = $this->createMediaObject($alice, 'RESUME-BYTES', 'resume.pdf');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/me/export', [
            'json' => ['currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);

        // sync:// transport: the zip was built and the email sent inline.
        $this->assertEmailCount(1);
        $message = $this->getMailerMessage(0);
        self::assertNotNull($message);
        $this->assertEmailHeaderSame($message, 'To', 'alice@example.com');
        $token = $this->extractToken($message);

        $this->entityManager->clear();
        $export = $this->entityManager->getRepository(AccountExport::class)->findOneBy([]);
        $this->assertNotNull($export);
        $this->assertSame(AccountExport::STATUS_COMPLETED, $export->getStatus());
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
        $this->assertZipContains($path, 'account.json', 'alice@example.com');
        $this->assertZipContains($path, 'account.json', 'Pack the boxes');
        $this->assertZipContains($path, 'account.json', 'resume.pdf');
        $this->assertZipContains(
            $path,
            'attachments/' . $media->getId() . '-resume.pdf',
            'RESUME-BYTES',
        );

        // Status endpoint says ready…
        $client->request('GET', '/account-exports/' . $token);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'ready']);

        // …and the download serves the archive.
        $client->request('GET', '/account-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('content-type', 'application/zip');
    }

    public function testDownloadIsRestrictedToRequester(): void
    {
        $alice = $this->createUser('alice@example.com');
        $mallory = $this->createUser('mallory@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/me/export', [
            'json' => ['currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $message = $this->getMailerMessage(0);
        self::assertNotNull($message);
        $token = $this->extractToken($message);

        // Someone else's session can't use the link — even the right token.
        $client->loginUser($mallory);
        $client->request('GET', '/account-exports/' . $token);
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/account-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(404);

        // Signed out, the link is useless.
        $anonymous = static::createClient();
        $anonymous->request('GET', '/account-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testExpiredExportReportsExpiredAndBlocksDownload(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/me/export', [
            'json' => ['currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $message = $this->getMailerMessage(0);
        self::assertNotNull($message);
        $token = $this->extractToken($message);

        $this->entityManager->clear();
        $export = $this->entityManager->getRepository(AccountExport::class)->findOneBy([]);
        $this->assertNotNull($export);
        $export->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();

        $client->request('GET', '/account-exports/' . $token);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'expired']);

        $client->request('GET', '/account-exports/' . $token . '/download');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSecondRequestWhileInFlightConflicts(): void
    {
        $alice = $this->createUser('alice@example.com');

        // Simulate a queued-but-unprocessed export (in prod the worker
        // hasn't picked it up yet; under sync:// we have to seed it).
        $pending = new AccountExport($alice);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/me/export', [
            'json' => ['currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testNewExportSupersedesPreviousOneForUser(): void
    {
        $alice = $this->createUser('alice@example.com');

        // Seed a prior completed export, with a real file on disk to prove
        // the supersede deletes both row and file.
        $oldFile = tempnam(sys_get_temp_dir(), 'madori-account-export-old-');
        $this->assertIsString($oldFile);
        $previous = new AccountExport($alice);
        $previous->setStatus(AccountExport::STATUS_COMPLETED);
        $previous->setFilePath($oldFile);
        $previous->setTokenHash(hash('sha256', 'old-token'));
        $previous->setCompletedAt(new \DateTimeImmutable('-1 day'));
        $previous->setExpiresAt(new \DateTimeImmutable('+6 days'));
        $this->entityManager->persist($previous);
        $this->entityManager->flush();
        $previousId = (string) $previous->getId();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/me/export', [
            'json' => ['currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $this->entityManager->clear();

        // Exactly one export remains for the user — the new one.
        $remaining = $this->entityManager->getRepository(AccountExport::class)->findAll();
        $this->assertCount(1, $remaining);
        $this->assertNotSame($previousId, (string) $remaining[0]->getId());
        $this->assertSame(AccountExport::STATUS_COMPLETED, $remaining[0]->getStatus());

        // The superseded archive's file is gone.
        $this->assertFileDoesNotExist($oldFile);
    }

    public function testPrunerDeletesExpiredExportsAndFiles(): void
    {
        $alice = $this->createUser('alice@example.com');

        $expiredFile = tempnam(sys_get_temp_dir(), 'madori-account-export-test-');
        $this->assertIsString($expiredFile);

        $expired = new AccountExport($alice);
        $expired->setStatus(AccountExport::STATUS_COMPLETED);
        $expired->setFilePath($expiredFile);
        $expired->setTokenHash(hash('sha256', 'expired-token'));
        $expired->setCompletedAt(new \DateTimeImmutable('-10 days'));
        $expired->setExpiresAt(new \DateTimeImmutable('-3 days'));
        $this->entityManager->persist($expired);

        $fresh = new AccountExport($alice);
        $fresh->setStatus(AccountExport::STATUS_COMPLETED);
        $fresh->setTokenHash(hash('sha256', 'fresh-token'));
        $fresh->setCompletedAt(new \DateTimeImmutable('-1 day'));
        $fresh->setExpiresAt(new \DateTimeImmutable('+6 days'));
        $this->entityManager->persist($fresh);

        $stalePending = new AccountExport($alice);
        $this->entityManager->persist($stalePending);
        $this->entityManager->flush();

        // Backdate the stuck pending row past the retention window.
        $this->entityManager->createQuery(
            'UPDATE App\Entity\AccountExport e SET e.createdAt = :old WHERE e.id = :id',
        )
            ->setParameter('old', new \DateTimeImmutable('-30 days'))
            ->setParameter('id', $stalePending->getId())
            ->execute();
        $this->entityManager->clear();

        $pruner = static::getContainer()->get(AccountExportPruner::class);
        $deleted = $pruner->prune();

        $this->assertSame(2, $deleted);
        $this->assertFileDoesNotExist($expiredFile);

        $remaining = $this->entityManager->getRepository(AccountExport::class)->findAll();
        $this->assertCount(1, $remaining);
        $this->assertSame((string) $fresh->getId(), (string) $remaining[0]->getId());
    }

    private function extractToken(RawMessage $message): string
    {
        $this->assertInstanceOf(Email::class, $message);
        $text = $message->getTextBody();
        $this->assertIsString($text);
        if (1 !== preg_match('#/account-exports/([0-9a-f]{64})#', $text, $matches)) {
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

    private function createTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    private function createMediaObject(User $owner, string $bytes, string $name): MediaObject
    {
        $path = 'attachments/account-export-test-' . bin2hex(random_bytes(4)) . '-' . $name;
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
