<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Segment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Edge/error branches of {@see \App\Controller\SegmentMemberController}
 * that SegmentTest doesn't reach: non-admin forbidden, unknown segment,
 * unknown email, the re-POST mode flip, DELETE of an unknown member, and
 * the preview breakdown shape.
 */
class SegmentMemberControllerTest extends ApiTestCase
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

    public function testNonAdminForbidden(): void
    {
        $plain = $this->makeUser('plain@example.com');
        $segment = $this->makeSegment('beta');

        $client = static::createClient();
        $client->loginUser($plain);

        $client->request('POST', '/segments/' . $segment->getId() . '/members', [
            'json' => ['email' => 'plain@example.com', 'mode' => 'include'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('GET', '/segments/' . $segment->getId() . '/preview');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnknownSegmentReturns404(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/segments/01919999-9999-7999-9999-999999999999/members', [
            'json' => ['email' => 'admin@example.com', 'mode' => 'include'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownEmailReturns404(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $segment = $this->makeSegment('beta');

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/segments/' . $segment->getId() . '/members', [
            'json' => ['email' => 'nobody@example.com', 'mode' => 'include'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidModeReturns422(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $segment = $this->makeSegment('beta');

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/segments/' . $segment->getId() . '/members', [
            'json' => ['email' => 'admin@example.com', 'mode' => 'maybe'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRePostFlipsMode(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $target = $this->makeUser('target@example.com');
        $segment = $this->makeSegment('beta');

        $client = static::createClient();
        $client->loginUser($admin);

        // First POST creates the override → 201 "added".
        $client->request('POST', '/segments/' . $segment->getId() . '/members', [
            'json' => ['email' => 'target@example.com', 'mode' => 'include'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $body = $this->body($client);
        $this->assertSame('added', $this->stringField($body, 'status'));
        $this->assertSame('include', $this->stringField($body, 'mode'));

        // Re-POST with a new mode updates in place → 200 "updated".
        $client->request('POST', '/segments/' . $segment->getId() . '/members', [
            'json' => ['email' => 'target@example.com', 'mode' => 'exclude'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $body = $this->body($client);
        $this->assertSame('updated', $this->stringField($body, 'status'));
        $this->assertSame('exclude', $this->stringField($body, 'mode'));
    }

    public function testRemoveUnknownMemberReturns404(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $other = $this->makeUser('other@example.com');
        $segment = $this->makeSegment('beta');

        $client = static::createClient();
        $client->loginUser($admin);

        // A real user that has no override on this segment.
        $client->request('DELETE', '/segments/' . $segment->getId() . '/members/' . $other->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPreviewBreakdownShape(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $segment = $this->makeSegment('beta', rollout: 100);

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('GET', '/segments/' . $segment->getId() . '/preview');
        $this->assertResponseIsSuccessful();

        $body = $this->body($client);
        $this->assertTrue($this->boolField($body, 'enabled'));
        $this->assertSame(100, $this->intField($body, 'rolloutPercentage'));

        $reach = $this->arrayField($body, 'reach');
        $this->assertArrayHasKey('total', $reach);
        $this->assertArrayHasKey('manualIncludes', $reach);
        $this->assertArrayHasKey('byRole', $reach);
        $this->assertArrayHasKey('byRollout', $reach);
        $this->assertArrayHasKey('manualExcludes', $reach);
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

    private function makeSegment(string $key, int $rollout = 0): Segment
    {
        $segment = new Segment();
        $segment->setKey($key);
        $segment->setName(ucfirst($key));
        $segment->setRolloutPercentage($rollout);
        $this->em->persist($segment);
        $this->em->flush();
        return $segment;
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
