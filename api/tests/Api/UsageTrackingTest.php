<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\UsageRecorder;
use App\Service\UsageSnapshotBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers per-user usage tracking: the live counters (API calls via the
 * terminate listener, MCP calls via the controller chokepoint) and the
 * nightly snapshot rollup.
 */
class UsageTrackingTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\UserUsageSnapshot')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserUsageCounter')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ApiToken')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAuthenticatedRequestsIncrementApiCounter(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/me/preferences');
        $this->assertResponseIsSuccessful();
        $client->request('GET', '/me/preferences');
        $this->assertResponseIsSuccessful();

        $this->assertSame(2, $this->counterFor($alice, UsageRecorder::METRIC_API_CALL));
    }

    public function testMcpToolCallIncrementsMcpCounterNotApi(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $client->request('POST', '/mcp', [
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'create_task', 'arguments' => ['title' => 'From MCP']],
            ],
            'headers' => ['Authorization' => 'Bearer ' . $plain],
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertSame(1, $this->counterFor($alice, UsageRecorder::METRIC_MCP_CALL));
        // The /mcp envelope must NOT also be counted as an API call.
        $this->assertSame(0, $this->counterFor($alice, UsageRecorder::METRIC_API_CALL));
    }

    public function testSnapshotRollsUpCountsForTheDay(): void
    {
        $alice = $this->createUser('alice@example.com');

        $container = static::getContainer();
        $recorder = $container->get(UsageRecorder::class);
        $recorder->recordApiCall($alice);
        $recorder->recordApiCall($alice);
        $recorder->recordApiCall($alice);
        $recorder->recordMcpCall($alice);

        $builder = $container->get(UsageSnapshotBuilder::class);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $written = $builder->capture($today);

        $this->assertSame(1, $written);

        $row = $this->connection()->fetchAssociative(
            'SELECT api_calls, mcp_calls, disk_bytes FROM user_usage_snapshot WHERE captured_on = :d',
            ['d' => $today->format('Y-m-d')],
        );
        $this->assertIsArray($row);
        $this->assertSame(3, $this->intOf($row['api_calls']));
        $this->assertSame(1, $this->intOf($row['mcp_calls']));
        $this->assertSame(0, $this->intOf($row['disk_bytes']));
    }

    private function counterFor(User $user, string $metric): int
    {
        $id = $user->getId();
        self::assertNotNull($id);

        // The recorder autocommits, so a fresh query off the current
        // container sees the rows even after the test client reboots the
        // kernel between requests.
        $value = $this->connection()->fetchOne(
            'SELECT count FROM user_usage_counter WHERE user_id = :u AND metric = :m',
            ['u' => $id->toRfc4122(), 'm' => $metric],
        );

        return $this->intOf($value);
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);

        return $em->getConnection();
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function mintToken(User $user, string $name): string
    {
        $plain = ApiToken::PLAINTEXT_PREFIX . bin2hex(random_bytes(16));
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName($name);
        $token->setTokenHash(hash('sha256', $plain));
        $token->setExpiresAt(null);
        $token->setScopes([]);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plain;
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
