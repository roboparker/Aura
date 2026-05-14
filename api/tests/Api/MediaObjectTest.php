<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class MediaObjectTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;
    private string $tempDir;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();

        $this->tempDir = sys_get_temp_dir() . '/aura-media-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach (false !== $files ? $files : [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testUnauthenticatedUploadRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/media-objects', [
            'extra' => ['files' => ['file' => $this->createPngUpload()]],
        ]);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertContains(
            $response->getStatusCode(),
            [401, 403],
            'Unauthenticated upload must be denied.',
        );
    }

    public function testAuthenticatedUploadSucceeds(): void
    {
        $user = $this->createTestUser('uploader@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/media-objects', [
            'extra' => ['files' => ['file' => $this->createPngUpload()]],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('avatar', $body['kind']);
        $this->assertArrayHasKey('variantUrls', $body);
        $variantUrls = $body['variantUrls'];
        $this->assertIsArray($variantUrls);
        $this->assertArrayHasKey('profile', $variantUrls);
        $this->assertArrayHasKey('thumb', $variantUrls);
        $profile = $variantUrls['profile'];
        $this->assertIsString($profile);
        $this->assertStringStartsWith('/media/avatars/', $profile);
    }

    public function testRejectsNonImageMimeType(): void
    {
        $user = $this->createTestUser('bad-mime@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $txtPath = $this->tempDir . '/not-an-image.txt';
        file_put_contents($txtPath, 'this is not an image');
        $upload = new UploadedFile($txtPath, 'not-an-image.txt', 'text/plain', null, true);

        $client->request('POST', '/media-objects', [
            'extra' => ['files' => ['file' => $upload]],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testRejectsMissingFile(): void
    {
        $user = $this->createTestUser('missing@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/media-objects', ['extra' => ['files' => []]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testPatchUserAvatarLinksMediaObject(): void
    {
        $user = $this->createTestUser('link@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/media-objects', [
            'extra' => ['files' => ['file' => $this->createPngUpload()]],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $iri = $response->toArray()['@id'];
        $this->assertIsString($iri);

        $client->request('PATCH', '/users/' . $user->getId(), [
            'json' => ['avatar' => $iri],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $me = $response->toArray();
        $avatarUrls = $me['avatarUrls'] ?? null;
        $this->assertIsArray($avatarUrls);
        $profile = $avatarUrls['profile'];
        $this->assertIsString($profile);
        $this->assertStringStartsWith('/media/avatars/', $profile);
    }

    private function createPngUpload(): UploadedFile
    {
        $path = $this->tempDir . '/sample.png';
        // 128x128 PNG is large enough for the 256 cover() to exercise real processing.
        $image = imagecreatetruecolor(128, 128);
        self::assertNotFalse($image);
        $color = imagecolorallocate($image, 200, 100, 50);
        self::assertNotFalse($color);
        imagefill($image, 0, 0, $color);
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'sample.png', 'image/png', null, true);
    }

    private function createTestUser(string $email): User
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
