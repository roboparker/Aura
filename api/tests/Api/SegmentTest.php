<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Segment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SegmentTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\SegmentMembership')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Segment')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testNonAdminCannotListOrCreate(): void
    {
        $user = $this->makeUser('plain@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('GET', '/segments');
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/segments', [
            'json' => ['key' => 'beta', 'name' => 'Beta'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatesSegmentAndStampsCreator(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/segments', [
            'json' => ['key' => 'beta-new-editor', 'name' => 'New editor', 'rolloutPercentage' => 30],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $this->body($client);
        $this->assertSame('ROLE_SEGMENT_BETA_NEW_EDITOR', $this->stringField($body, 'role'));

        $segment = $this->em->getRepository(Segment::class)->findOneByKey('beta-new-editor');
        assert($segment instanceof Segment);
        $this->assertSame('admin@example.com', $segment->getCreatedBy()?->getEmail());
    }

    public function testIncludedRolesRejectsSegmentRole(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/segments', [
            'json' => ['key' => 'beta', 'name' => 'Beta', 'includedRoles' => ['ROLE_SEGMENT_OTHER']],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testApiMeExposesSegmentRoleAndExcludeRemovesIt(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $segment = new Segment();
        $segment->setKey('rolled-out');
        $segment->setName('Rolled out');
        $segment->setRolloutPercentage(100);
        $this->em->persist($segment);
        $this->em->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        // 100% rollout → the admin is in, so /api/me carries the segment role.
        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $roles = $this->arrayField($this->body($client), 'roles');
        $this->assertContains('ROLE_SEGMENT_ROLLED_OUT', $roles);

        // A manual exclude override drops them back out.
        $client->request('POST', '/segments/' . $segment->getId() . '/members', [
            'json' => ['email' => 'admin@example.com', 'mode' => 'exclude'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $client->request('GET', '/api/me');
        $roles = $this->arrayField($this->body($client), 'roles');
        $this->assertNotContains('ROLE_SEGMENT_ROLLED_OUT', $roles);
    }

    public function testPreviewReturnsReach(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $this->makeUser('a@example.com');
        $this->makeUser('b@example.com');

        $segment = new Segment();
        $segment->setKey('everyone');
        $segment->setName('Everyone');
        $segment->setRolloutPercentage(100);
        $this->em->persist($segment);
        $this->em->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('GET', '/segments/' . $segment->getId() . '/preview');
        $this->assertResponseIsSuccessful();

        $reach = $this->arrayField($this->body($client), 'reach');
        // admin + a + b are all included at 100% rollout.
        $this->assertSame(3, $this->intField($reach, 'total'));
        $this->assertSame(3, $this->intField($reach, 'byRollout'));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function body(Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        return $response->toArray(false);
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
