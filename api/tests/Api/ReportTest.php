<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Billing reports (#647): the time summary (period + group-by) and the
 * per-engagement uninvoiced pool, both gated on the admin-reserved `invoices`
 * category and exportable as CSV.
 */
class ReportTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Invoice')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\EngagementCategory')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Engagement')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testTimeSummaryGroupsTotalsAndExportsCsv(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceId = (string) $space->getId();
        $spaceIri = '/spaces/' . $spaceId;

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        [$bpIri, $devCat, $buildCat, $internalCat] = $this->createEngagement($client, $spaceIri, $this->iri($clientRow));

        // 1h Dev @6000 + 30m Build @8000 (billable) + 2h Internal (non-billable).
        $this->trackTime($client, $spaceIri, $bpIri, $devCat, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00');
        $this->trackTime($client, $spaceIri, $bpIri, $buildCat, '2026-07-03T11:00:00+00:00', '2026-07-03T11:30:00+00:00');
        $this->trackTime($client, $spaceIri, $bpIri, $internalCat, '2026-07-04T09:00:00+00:00', '2026-07-04T11:00:00+00:00');

        $report = $client->request('GET', '/spaces/' . $spaceId . '/reports/time-summary?groupBy=category')->toArray();
        $this->assertResponseStatusCodeSame(200);
        $rows = $report['rows'];
        $this->assertIsArray($rows);
        $this->assertCount(3, $rows);
        $byLabel = [];
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $label = $row['label'];
            $this->assertIsString($label);
            $byLabel[$label] = $row;
        }
        $this->assertSame(1.0, $byLabel['Dev']['hours'] ?? null);
        $this->assertSame(['USD' => 6000], $byLabel['Dev']['amounts'] ?? null);
        $this->assertSame(0.5, $byLabel['Build']['billableHours'] ?? null);
        $this->assertSame(['USD' => 4000], $byLabel['Build']['amounts'] ?? null);
        $this->assertSame(2.0, $byLabel['Internal']['hours'] ?? null);
        $this->assertSame(0.0, $byLabel['Internal']['billableHours'] ?? null);
        $this->assertSame([], $byLabel['Internal']['amounts'] ?? null);
        $totals = $report['totals'];
        $this->assertIsArray($totals);
        $this->assertSame(3.5, $totals['hours'] ?? null);
        $this->assertSame(1.5, $totals['billableHours'] ?? null);
        $this->assertSame(['USD' => 10000], $totals['amounts'] ?? null);

        // A range that misses everything comes back empty.
        $empty = $client->request('GET', '/spaces/' . $spaceId . '/reports/time-summary?from=2030-01-01')->toArray();
        $this->assertSame([], $empty['rows'] ?? null);

        // Unknown groupBy is rejected.
        $client->request('GET', '/spaces/' . $spaceId . '/reports/time-summary?groupBy=bogus');
        $this->assertResponseStatusCodeSame(422);

        // CSV export mirrors the same data.
        $csv = $client->request('GET', '/spaces/' . $spaceId . '/reports/time-summary?groupBy=category&format=csv');
        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Dev', $csv->getContent());
        $this->assertStringContainsString('60.00 USD', $csv->getContent());

        // A plain member (no invoicing grant) is refused; an outsider gets 404.
        $client->loginUser($member);
        $client->request('GET', '/spaces/' . $spaceId . '/reports/time-summary');
        $this->assertResponseStatusCodeSame(403);
        $outsider = $this->createUser('outsider@example.com');
        $client->loginUser($outsider);
        $client->request('GET', '/spaces/' . $spaceId . '/reports/time-summary');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUninvoicedReportShowsThePoolAndEmptiesAfterInvoicing(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceId = (string) $space->getId();
        $spaceIri = '/spaces/' . $spaceId;

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        [$bpIri, $devCat, $buildCat, $internalCat] = $this->createEngagement($client, $spaceIri, $this->iri($clientRow));

        $this->trackTime($client, $spaceIri, $bpIri, $devCat, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00');
        $this->trackTime($client, $spaceIri, $bpIri, $buildCat, '2026-07-03T11:00:00+00:00', '2026-07-03T11:30:00+00:00');
        // Non-billable time never enters the pool.
        $this->trackTime($client, $spaceIri, $bpIri, $internalCat, '2026-07-04T09:00:00+00:00', '2026-07-04T11:00:00+00:00');

        $report = $client->request('GET', '/spaces/' . $spaceId . '/reports/uninvoiced')->toArray();
        $this->assertResponseStatusCodeSame(200);
        $rows = $report['rows'];
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertIsArray($row);
        $this->assertSame('Acme Website', $row['engagement'] ?? null);
        $this->assertSame('Acme Co', $row['client'] ?? null);
        $this->assertSame(2, $row['entryCount'] ?? null);
        $this->assertSame(1.5, $row['hours'] ?? null);
        $this->assertSame(10000, $row['amount'] ?? null);
        $this->assertSame('USD', $row['currency'] ?? null);

        // Invoicing the engagement drains the pool.
        $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['engagement' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $after = $client->request('GET', '/spaces/' . $spaceId . '/reports/uninvoiced')->toArray();
        $this->assertSame([], $after['rows'] ?? null);
    }

    /**
     * Admin creates a engagement (for $clientIri) with two billable categories
     * ("Dev" @ 6000, "Build" @ 8000) and one non-billable ("Internal" @ 0).
     * Returns [engagementIri, devCategoryIri, buildCategoryIri, internalCategoryIri].
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function createEngagement(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        string $spaceIri,
        string $clientIri,
    ): array {
        $row = $client->request('POST', '/engagements', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientIri,
                'name' => 'Acme Website',
                'currency' => 'USD',
                'categories' => [
                    ['name' => 'Dev', 'rateAmount' => 6000, 'position' => 0],
                    ['name' => 'Build', 'rateAmount' => 8000, 'position' => 1],
                    ['name' => 'Internal', 'rateAmount' => 0, 'position' => 2, 'billable' => false],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);

        $bpIri = $this->iri($row);
        $categories = $row['categories'] ?? [];
        $this->assertIsArray($categories);
        $byName = [];
        foreach ($categories as $category) {
            $this->assertIsArray($category);
            $name = $category['name'] ?? null;
            $iri = $category['@id'] ?? null;
            if (is_string($name) && is_string($iri)) {
                $byName[$name] = $iri;
            }
        }
        $this->assertArrayHasKey('Dev', $byName);
        $this->assertArrayHasKey('Build', $byName);
        $this->assertArrayHasKey('Internal', $byName);

        return [$bpIri, $byName['Dev'], $byName['Build'], $byName['Internal']];
    }

    private function trackTime(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        string $spaceIri,
        string $engagementIri,
        string $categoryIri,
        string $startedAt,
        string $endedAt,
    ): void {
        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'engagement' => $engagementIri,
                'category' => $categoryIri,
                'startedAt' => $startedAt,
                'endedAt' => $endedAt,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    /**
     * The `@id` of a decoded resource, narrowed to string for concatenation.
     *
     * @param array<int|string, mixed> $row
     */
    private function iri(array $row): string
    {
        $iri = $row['@id'] ?? null;
        $this->assertIsString($iri);

        return $iri;
    }

    private function createSharedSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Studio')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())
            ->setUser($admin)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $memberMembership = (new SpaceMembership())
                ->setUser($member)
                ->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($memberMembership);
            $this->entityManager->persist($memberMembership);
        }
        $this->entityManager->flush();

        return $space;
    }

    private function createUser(string $email): User
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
