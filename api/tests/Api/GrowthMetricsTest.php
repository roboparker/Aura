<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Subscription;
use App\Entity\Task;
use App\Entity\User;
use App\Growth\GrowthMetrics;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Growth funnel + snapshot (#763).
 *
 * Two things need proving: that only admins can read it (it exposes the whole
 * instance's numbers), and that the arithmetic is right — a funnel that
 * silently miscounts is worse than no funnel, because it gets believed.
 */
class GrowthMetricsTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        foreach (['App\Entity\Subscription', 'App\Entity\Task', 'App\Entity\User'] as $entity) {
            $this->entityManager->createQuery('DELETE FROM ' . $entity)->execute();
        }
    }

    public function testNonAdminsAreRefused(): void
    {
        $user = $this->makeUser('plain@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('GET', '/admin/growth');

        // The whole instance's numbers — not something a customer may read.
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsRefused(): void
    {
        static::createClient()->request('GET', '/admin/growth');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testSignupsAreBucketedByDay(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->makeUser('a@example.com');
        $this->makeUser('b@example.com');
        $this->entityManager->flush();

        // Backdate two of the three so the buckets are distinguishable.
        $this->stampCreatedAt('a@example.com', '2026-03-02 10:00:00');
        $this->stampCreatedAt('b@example.com', '2026-03-02 18:00:00');
        $this->stampCreatedAt('admin@example.com', '2026-03-05 09:00:00');

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/admin/growth?from=2026-03-01&to=2026-03-31&interval=day')->toArray();
        $this->assertResponseIsSuccessful();

        $funnel = $this->arrayField($body, 'funnel');
        $signups = $funnel['signups'];
        $this->assertIsArray($signups);

        $flat = $this->flatten($signups);
        $this->assertSame(['2026-03-02' => 2, '2026-03-05' => 1], $flat);
    }

    /**
     * Activation is bucketed by the first task, not by signup — otherwise the
     * series would just be signups again with extra steps.
     */
    public function testActivationFollowsTheFirstTaskNotTheSignup(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user = $this->makeUser('late@example.com');
        $this->entityManager->flush();
        $this->stampCreatedAt('late@example.com', '2026-03-01 09:00:00');

        // Signed up in March, first task in April, plus a second task later
        // that must not count again.
        $this->makeTask($user, '2026-04-10 09:00:00');
        $this->makeTask($user, '2026-04-20 09:00:00');

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/admin/growth?from=2026-03-01&to=2026-04-30&interval=month')->toArray();
        $funnel = $this->arrayField($body, 'funnel');

        $activations = $funnel['activations'];
        $this->assertIsArray($activations);
        // Counted once, in April — the month they got going, not March.
        $this->assertSame(['2026-04' => 1], $this->flatten($activations));
    }

    public function testSnapshotCountsAndEstimatesMrr(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $active = $this->makeUser('paying@example.com');
        $this->makeUser('free@example.com');
        $this->entityManager->flush();

        $this->makeTask($active, '2026-04-01 09:00:00');

        // Pro monthly (1 seat), Business monthly (3 seats), and a canceled one
        // that must not count toward MRR.
        $this->makeSubscription($active, 'pro', 'month', 1, Subscription::STATUS_ACTIVE);
        $this->makeSubscription($active, 'business', 'month', 3, Subscription::STATUS_ACTIVE);
        $this->makeSubscription($active, 'pro', 'month', 1, Subscription::STATUS_CANCELED);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/admin/growth')->toArray();
        $snapshot = $this->arrayField($body, 'snapshot');

        $this->assertSame(3, $this->intField($snapshot, 'users'));
        $this->assertSame(1, $this->intField($snapshot, 'activated'));
        $this->assertSame(2, $this->intField($snapshot, 'paidSubscriptions'), 'The canceled one must not count.');
        $this->assertSame(4, $this->intField($snapshot, 'paidSeats'));
        // $10 x 1 + $15 x 3 = $55.00
        $this->assertSame(5500, $this->intField($snapshot, 'mrrCents'));
    }

    /** Annual bills at 10x monthly, so its monthly-equivalent is lower. */
    public function testAnnualSubscriptionsContributeTheirMonthlyEquivalent(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->entityManager->flush();
        $this->makeSubscription($admin, 'pro', 'year', 1, Subscription::STATUS_ACTIVE);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/admin/growth')->toArray();
        $snapshot = $this->arrayField($body, 'snapshot');

        // $10 x 10 months / 12 = $8.33
        $this->assertSame(833, $this->intField($snapshot, 'mrrCents'));
    }

    public function testTrialingCountsAsPaidButUnpaidDoesNot(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->entityManager->flush();
        // Trialing is entitling — they have access, so they're in the funnel.
        $this->makeSubscription($admin, 'pro', 'month', 1, Subscription::STATUS_TRIALING);
        $this->makeSubscription($admin, 'pro', 'month', 1, Subscription::STATUS_UNPAID);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/admin/growth')->toArray();
        $this->assertSame(1, $this->intField($this->arrayField($body, 'snapshot'), 'paidSubscriptions'));
    }

    public function testRejectsBadIntervalAndOversizedRange(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('GET', '/admin/growth?interval=fortnight');
        $this->assertResponseStatusCodeSame(422);

        $client->request('GET', '/admin/growth?from=2026-06-01&to=2026-01-01');
        $this->assertResponseStatusCodeSame(422);

        $client->request('GET', '/admin/growth?from=2000-01-01&to=2026-01-01');
        $this->assertResponseStatusCodeSame(422);

        $client->request('GET', '/admin/growth?from=not-a-date');
        $this->assertResponseStatusCodeSame(422);
    }

    /** Today's signups must land inside a range whose `to` is today. */
    public function testTodayIsIncludedInTheDefaultWindow(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $body = $client->request('GET', '/admin/growth?to=' . $today . '&interval=day')->toArray();

        $funnel = $this->arrayField($body, 'funnel');
        $signups = $funnel['signups'];
        $this->assertIsArray($signups);
        $this->assertArrayHasKey($today, $this->flatten($signups));
    }

    public function testIntervalIsNeverInterpolatedIntoSql(): void
    {
        // Belt and braces on the injection guard: the interval reaches
        // date_trunc through a fixed lookup, so a hostile value can only ever
        // be rejected by the whitelist.
        $this->assertFalse(GrowthMetrics::isValidInterval("day'); DROP TABLE \"user\"; --"));
        $this->assertTrue(GrowthMetrics::isValidInterval('day'));
    }

    /**
     * @param array<int|string, mixed> $rows
     *
     * @return array<string, int>
     */
    private function flatten(array $rows): array
    {
        $flat = [];
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $flat[$this->stringField($row, 'period')] = $this->intField($row, 'value');
        }

        return $flat;
    }

    private function stampCreatedAt(string $email, string $when): void
    {
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            'UPDATE "user" SET created_at = :when WHERE email = :email',
            ['when' => $when, 'email' => $email],
        );
    }

    private function makeTask(User $owner, string $createdOn): void
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle('Something');
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        // createdOn is set by the entity/listener, so backdate it in SQL.
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            'UPDATE task SET created_on = :when WHERE id = :id',
            ['when' => $createdOn, 'id' => (string) $task->getId()],
        );
    }

    private function makeSubscription(
        User $owner,
        string $plan,
        string $interval,
        int $seats,
        string $status,
    ): void {
        $sub = new Subscription();
        $sub->setOwnerUser($owner);
        $sub->setPlan($plan);
        $sub->setBillingInterval($interval);
        $sub->setSeats($seats);
        $sub->setStatus($status);
        $this->entityManager->persist($sub);
    }

    /** @param list<string> $roles */
    private function makeUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->entityManager->persist($user);
        // Flush here, not at the call site: loginUser() reloads the user from
        // the database, so an unflushed one authenticates as nobody and the
        // test sees a misleading 401.
        $this->entityManager->flush();

        return $user;
    }
}
