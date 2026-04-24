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
        $this->entityManager = $kernel->getContainer()->get('doctrine')->getManager();
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();

        $this->tempDir = sys_get_temp_dir() . '/aura-media-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
    }

    public function testUnauthenticatedUploadRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/media-objects', [
            'extra' => ['files' => ['file' => $this->createPngUpload()]],
        ]);

        $this->assertContains(
            $client->getResponse()->getStatusCode(),
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
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('avatar', $response['kind']);
        $this->assertArrayHasKey('variantUrls', $response);
        $this->assertArrayHasKey('profile', $response['variantUrls']);
        $this->assertArrayHasKey('thumb', $response['variantUrls']);
        $this->assertStringStartsWith('/media/avatars/', $response['variantUrls']['profile']);
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
        $iri = json_decode($client->getResponse()->getContent(), true)['@id'];

        $client->request('PATCH', '/users/' . $user->getId(), [
            'json' => ['avatar' => $iri],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $me = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotNull($me['avatarUrls']);
        $this->assertStringStartsWith('/media/avatars/', $me['avatarUrls']['profile']);
    }

    private function createPngUpload(): UploadedFile
    {
        $path = $this->tempDir . '/sample.png';
        // 128x128 PNG is large enough for the 256 cover() to exercise real processing.
        $image = imagecreatetruecolor(128, 128);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 100, 50));
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'sample.png', 'image/png', null, true);
    }

    private function createTestUser(string $email): User
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
}
