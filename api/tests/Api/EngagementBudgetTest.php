<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Engagement;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Engagement budgets + threshold alerts (#651): hours/fees budgets, the
 * transient budgetSpent read-side, and the nightly 80%/100% alert sweep with
 * its per-threshold idempotency ledger.
 */
class EngagementBudgetTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Expense')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\EngagementCategory')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Engagement')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testBudgetProgressAndThresholdAlerts(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $clientIri = $clientRow['@id'];
        $this->assertIsString($clientIri);

        // A budget type without an amount is rejected.
        $client->request('POST', '/engagements', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientIri,
                'name' => 'No amount',
                'currency' => 'USD',
                'budgetType' => 'hours',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // A 2-hour budget (120 minutes) with one billable category.
        $engagement = $client->request('POST', '/engagements', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientIri,
                'name' => 'Budgeted work',
                'currency' => 'USD',
                'budgetType' => 'hours',
                'budgetAmount' => 120,
                'categories' => [['name' => 'Dev', 'rateAmount' => 6000, 'position' => 0]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $engagementIri = $engagement['@id'];
        $this->assertIsString($engagementIri);
        $categories = $engagement['categories'] ?? null;
        $this->assertIsArray($categories);
        $first = $categories[0];
        $this->assertIsArray($first);
        $categoryIri = $first['@id'];
        $this->assertIsString($categoryIri);

        $track = function (string $startedAt, string $endedAt) use ($client, $spaceIri, $engagementIri, $categoryIri): void {
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
        };

        // 1h tracked = 50% — progress surfaces, no alert threshold crossed.
        $track('2026-07-10T09:00:00+00:00', '2026-07-10T10:00:00+00:00');
        $list = $client->request('GET', '/engagements?space=' . $spaceIri)->toArray();
        $members = $list['member'] ?? $list['hydra:member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $row = $members[0];
        $this->assertIsArray($row);
        $this->assertSame('hours', $row['budgetType'] ?? null);
        $this->assertSame(120, $row['budgetAmount'] ?? null);
        $this->assertSame(60, $row['budgetSpent'] ?? null);

        // Another 1h = 100%: the sweep crosses 80 AND 100 in one run — one
        // email per threshold to the space admin. No HTTP requests after this
        // point: each client request reboots the kernel and resets the mailer
        // logger, so the counts below see only the in-process sweep sends.
        $track('2026-07-11T09:00:00+00:00', '2026-07-11T10:00:00+00:00');

        $alerter = static::getContainer()->get(\App\Service\EngagementBudgetAlerter::class);
        $sent = $alerter->dispatchDue();
        $this->assertSame(2, $sent);
        $this->assertEmailCount(2);
        $message = $this->getMailerMessage();
        $this->assertNotNull($message);
        $this->assertEmailAddressContains($message, 'To', 'admin@example.com');

        // Idempotent: a re-run finds both thresholds already stamped.
        $this->assertSame(0, $alerter->dispatchDue());
        $this->assertEmailCount(2);

        // Raising the budget clears the ledger; at 20% nothing re-fires yet.
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $engagementId = $engagement['id'];
        $this->assertIsString($engagementId);
        $entity = $em->getRepository(Engagement::class)->find($engagementId);
        $this->assertInstanceOf(Engagement::class, $entity);
        $entity->setBudgetAmount(600); // 10 hours
        $em->flush();
        $this->assertSame([], $entity->getBudgetAlertsSent());
        $this->assertSame(0, $alerter->dispatchDue());
        $this->assertEmailCount(2);
    }

    public function testFeesBudgetCountsBillableExpenses(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $clientIri = $clientRow['@id'];
        $this->assertIsString($clientIri);

        // A $100 fees budget (10000 minor units) with Dev @ $60/h.
        $engagement = $client->request('POST', '/engagements', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientIri,
                'name' => 'Fees budget',
                'currency' => 'USD',
                'budgetType' => 'fees',
                'budgetAmount' => 10000,
                'categories' => [['name' => 'Dev', 'rateAmount' => 6000, 'position' => 0]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $engagementIri = $engagement['@id'];
        $this->assertIsString($engagementIri);
        $categories = $engagement['categories'] ?? null;
        $this->assertIsArray($categories);
        $first = $categories[0];
        $this->assertIsArray($first);
        $categoryIri = $first['@id'];
        $this->assertIsString($categoryIri);

        // 1h Dev = 6000 in fees.
        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'engagement' => $engagementIri,
                'category' => $categoryIri,
                'startedAt' => '2026-07-10T09:00:00+00:00',
                'endedAt' => '2026-07-10T10:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // A billable expense adds to the burn; a non-billable one doesn't (#666).
        foreach ([[2500, true], [5000, false]] as [$amount, $billable]) {
            $client->request('POST', '/expenses', [
                'json' => [
                    'space' => $spaceIri,
                    'engagement' => $engagementIri,
                    'spentOn' => '2026-07-10',
                    'category' => 'Travel',
                    'amount' => $amount,
                    'billable' => $billable,
                ],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ]);
            $this->assertResponseStatusCodeSame(201);
        }

        $list = $client->request('GET', '/engagements?space=' . $spaceIri)->toArray();
        $members = $list['member'] ?? $list['hydra:member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $row = $members[0];
        $this->assertIsArray($row);
        // 6000 tracked + 2500 billable expense = 8500 (85%); the 5000
        // non-billable expense never counts.
        $this->assertSame(8500, $row['budgetSpent'] ?? null);

        // 85% crosses the 80 threshold only — one alert.
        $alerter = static::getContainer()->get(\App\Service\EngagementBudgetAlerter::class);
        $this->assertSame(1, $alerter->dispatchDue());
        $this->assertEmailCount(1);
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
