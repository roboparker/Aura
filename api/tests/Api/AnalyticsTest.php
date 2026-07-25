<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Client;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\TimeEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Analytics dashboard (#analytics): `GET /spaces/{id}/analytics`.
 *
 * The load-bearing test here is the permission split. Money metrics ride the
 * admin-reserved `invoices` category, so an ordinary member must get the time
 * metrics and *none* of the revenue ones — the exact trap that plain `can()`
 * (with its "member with no roles is unrestricted" rule) would walk into.
 */
class AnalyticsTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    /** @var array<string, Client> */
    private array $clients = [];

    protected function setUp(): void
    {
        $this->clients = [];
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Child-first, so the FKs come apart cleanly.
        $tearDownOrder = [
            'App\Entity\InvoicePayment',
            'App\Entity\InvoiceLineItem',
            'App\Entity\Invoice',
            'App\Entity\TimeEntry',
            'App\Entity\Service',
            'App\Entity\Project',
            'App\Entity\Client',
            'App\Entity\SpaceMembership',
            'App\Entity\Space',
            'App\Entity\User',
        ];
        foreach ($tearDownOrder as $entity) {
            $this->entityManager->createQuery('DELETE FROM ' . $entity)->execute();
        }
    }

    public function testSpaceAdminSeesBothMoneyAndTimeMetrics(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/spaces/' . $space->getId() . '/analytics')->toArray();
        $this->assertResponseIsSuccessful();

        $keys = $this->metricKeys($body);
        // Money (invoices category)…
        $this->assertContains('invoiced', $keys);
        $this->assertContains('collected', $keys);
        // …and time (time_entries category).
        $this->assertContains('tracked_time', $keys);
        $this->assertContains('billable_time', $keys);
        $this->assertContains('unbilled_value', $keys);
    }

    /**
     * The security-critical case: `invoices` is ADMIN_RESERVED, so a plain
     * member must not see revenue — while still getting the time metrics they
     * legitimately own.
     */
    public function testPlainMemberIsDeniedMoneyMetricsButKeepsTimeOnes(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $member = $this->makeUser('member@example.com');
        $space = $this->makeSpace($admin, $member);

        $client = static::createClient();
        $client->loginUser($member);

        $body = $client->request('GET', '/spaces/' . $space->getId() . '/analytics')->toArray();
        $this->assertResponseIsSuccessful();

        $keys = $this->metricKeys($body);
        $this->assertNotContains('invoiced', $keys, 'A plain member must not see revenue.');
        $this->assertNotContains('collected', $keys, 'A plain member must not see collections.');
        $this->assertContains('tracked_time', $keys);
        $this->assertContains('billable_time', $keys);
    }

    public function testExplicitlyRequestingADeniedMetricStillOmitsIt(): void
    {
        // Asking for it by name must not be a way around the gate.
        $admin = $this->makeUser('admin@example.com');
        $member = $this->makeUser('member@example.com');
        $space = $this->makeSpace($admin, $member);

        $client = static::createClient();
        $client->loginUser($member);

        $body = $client->request(
            'GET',
            '/spaces/' . $space->getId() . '/analytics?metrics=invoiced,tracked_time',
        )->toArray();
        $this->assertResponseIsSuccessful();

        $this->assertSame(['tracked_time'], $this->metricKeys($body));
    }

    public function testOutsidersGetTheExistenceHiding404(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $outsider = $this->makeUser('outsider@example.com');
        $space = $this->makeSpace($admin);

        $client = static::createClient();
        $client->loginUser($outsider);
        $client->request('GET', '/spaces/' . $space->getId() . '/analytics');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRejectsBadIntervalUnknownMetricAndInvertedRange(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);
        $base = '/spaces/' . $space->getId() . '/analytics';

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('GET', $base . '?interval=fortnight');
        $this->assertResponseStatusCodeSame(422);

        $client->request('GET', $base . '?metrics=profit');
        $this->assertResponseStatusCodeSame(422);

        $client->request('GET', $base . '?from=2026-06-01&to=2026-01-01');
        $this->assertResponseStatusCodeSame(422);

        // Guards against an unbounded scan.
        $client->request('GET', $base . '?from=2000-01-01&to=2026-01-01');
        $this->assertResponseStatusCodeSame(422);
    }

    public function testSeriesShapeAndDefaultWindow(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/spaces/' . $space->getId() . '/analytics?interval=month')->toArray();
        $this->assertSame('month', $this->stringField($body, 'interval'));
        // A default window is supplied when the caller gives no dates.
        $this->assertNotSame('', $this->stringField($body, 'from'));
        $this->assertNotSame('', $this->stringField($body, 'to'));

        $metrics = $this->arrayField($body, 'metrics');
        $first = $metrics[0];
        $this->assertIsArray($first);
        // Empty space → no rows, but the metric is still declared with its unit
        // so the client can render an empty chart rather than hide the card.
        $this->assertContains($this->stringField($first, 'unit'), ['money', 'seconds']);
        $this->assertIsArray($first['series']);
    }

    /**
     * The aggregation itself: buckets, the draft/void exclusion, and the
     * seconds→hours→money conversion that has to agree with invoice generation.
     */
    public function testAggregatesRealRowsIntoBuckets(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);

        // Two invoices in January, one in February, plus a draft and a void
        // that must not be counted at all.
        $this->makeInvoice($space, $admin, '2026-01-10', Invoice::STATUS_SENT, 10_000);
        $this->makeInvoice($space, $admin, '2026-01-20', Invoice::STATUS_PAID, 25_000);
        $this->makeInvoice($space, $admin, '2026-02-05', Invoice::STATUS_OVERDUE, 5_000);
        $this->makeInvoice($space, $admin, '2026-01-15', Invoice::STATUS_DRAFT, 99_000);
        $this->makeInvoice($space, $admin, '2026-01-16', Invoice::STATUS_VOID, 88_000);

        // 2h billable at 50.00/h = 100.00, unbilled. Plus 1h non-billable.
        $this->makeTimeEntry($space, $admin, '2026-01-12', 7200, true, 5_000);
        $this->makeTimeEntry($space, $admin, '2026-01-13', 3600, false, 5_000);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request(
            'GET',
            '/spaces/' . $space->getId() . '/analytics?from=2026-01-01&to=2026-02-28&interval=month',
        )->toArray();
        $this->assertResponseIsSuccessful();

        // Draft + void excluded; the rest bucketed by issue month.
        $this->assertSame(
            ['2026-01' => 35_000, '2026-02' => 5_000],
            $this->pointsOf($body, 'invoiced'),
        );

        // Both billable and non-billable time counts as tracked…
        $this->assertSame(['2026-01' => 10800], $this->pointsOf($body, 'tracked_time'));
        // …only the billable 2h is billable time.
        $this->assertSame(['2026-01' => 7200], $this->pointsOf($body, 'billable_time'));
        // 2h × 50.00 = 100.00, and the non-billable hour contributes nothing.
        $this->assertSame(['2026-01' => 10_000], $this->pointsOf($body, 'unbilled_value'));
        // Nothing has been paid yet.
        $this->assertSame([], $this->pointsOf($body, 'collected'));
    }

    public function testCollectedFollowsPaymentDateNotInvoiceDate(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);

        // Invoiced in January, paid in March — each lands in its own month.
        $invoice = $this->makeInvoice($space, $admin, '2026-01-10', Invoice::STATUS_PAID, 30_000);
        $payment = (new InvoicePayment())
            ->setInvoice($invoice)
            ->setAmount(30_000)
            ->setPaidOn(new \DateTimeImmutable('2026-03-02'))
            ->setCreatedBy($admin);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request(
            'GET',
            '/spaces/' . $space->getId() . '/analytics?from=2026-01-01&to=2026-03-31&interval=month',
        )->toArray();

        $this->assertSame(['2026-01' => 30_000], $this->pointsOf($body, 'invoiced'));
        $this->assertSame(['2026-03' => 30_000], $this->pointsOf($body, 'collected'));
    }

    public function testAnotherSpacesRowsAreNotCounted(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $mine = $this->makeSpace($admin);
        $theirs = $this->makeSpace($admin);

        $this->makeInvoice($theirs, $admin, '2026-01-10', Invoice::STATUS_SENT, 70_000);
        $this->makeTimeEntry($theirs, $admin, '2026-01-10', 3600, true, 5_000);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request(
            'GET',
            '/spaces/' . $mine->getId() . '/analytics?from=2026-01-01&to=2026-01-31&interval=month',
        )->toArray();

        $this->assertSame([], $this->pointsOf($body, 'invoiced'));
        $this->assertSame([], $this->pointsOf($body, 'tracked_time'));
    }

    /**
     * Flattens one metric's series to `period => value`.
     *
     * Multi-currency metrics would collapse here, but every fixture in this
     * class is single-currency, which keeps the assertions readable.
     *
     * @param array<int|string, mixed> $body
     *
     * @return array<string, int>
     */
    private function pointsOf(array $body, string $key): array
    {
        $metrics = $this->arrayField($body, 'metrics');
        foreach ($metrics as $metric) {
            $this->assertIsArray($metric);
            if ($this->stringField($metric, 'key') !== $key) {
                continue;
            }

            $flat = [];
            foreach ($this->arrayField($metric, 'series') as $series) {
                $this->assertIsArray($series);
                foreach ($this->arrayField($series, 'points') as $point) {
                    $this->assertIsArray($point);
                    $flat[$this->stringField($point, 'period')] = $this->intField($point, 'value');
                }
            }

            return $flat;
        }

        self::fail(sprintf('Metric "%s" is missing from the response.', $key));
    }

    private function makeInvoice(
        Space $space,
        User $author,
        string $issueDate,
        string $status,
        int $total,
    ): Invoice {
        $invoice = (new Invoice())
            ->setSpace($space)
            ->setClient($this->clientFor($space, $author))
            ->setStatus($status)
            ->setIssueDate(new \DateTimeImmutable($issueDate))
            ->setCurrency('USD')
            ->setSubtotal($total)
            ->setTotal($total)
            ->setCreatedBy($author);
        $this->entityManager->persist($invoice);

        return $invoice;
    }

    /** One client per space, reused across that space's invoices. */
    private function clientFor(Space $space, User $author): Client
    {
        $key = (string) $space->getId();
        if (!isset($this->clients[$key])) {
            $client = (new Client())
                ->setSpace($space)
                ->setName('Acme')
                ->setCurrency('USD')
                ->setCreatedBy($author);
            $this->entityManager->persist($client);
            $this->clients[$key] = $client;
        }

        return $this->clients[$key];
    }

    private function makeTimeEntry(
        Space $space,
        User $user,
        string $day,
        int $seconds,
        bool $billable,
        ?int $rateAmount,
    ): void {
        $started = new \DateTimeImmutable($day . ' 09:00:00');
        $entry = (new TimeEntry())
            ->setSpace($space)
            ->setUser($user)
            ->setStartedAt($started)
            ->setEndedAt($started->modify('+' . $seconds . ' seconds'))
            ->setBillable($billable)
            ->setRateAmount($rateAmount)
            ->setRateCurrency(null === $rateAmount ? null : 'USD');
        $this->entityManager->persist($entry);
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return list<string>
     */
    private function metricKeys(array $body): array
    {
        $metrics = $this->arrayField($body, 'metrics');
        $keys = [];
        foreach ($metrics as $metric) {
            $this->assertIsArray($metric);
            $keys[] = $this->stringField($metric, 'key');
        }

        return $keys;
    }

    private function makeSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Studio')->setCreatedBy($admin);
        $this->entityManager->persist($space);

        $space->addUserMembership(
            (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN),
        );
        if (null !== $member) {
            $space->addUserMembership(
                (new SpaceMembership())->setUser($member)->setRole(Space::ROLE_MEMBER),
            );
        }
        $this->entityManager->flush();

        return $space;
    }

    private function makeUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

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
