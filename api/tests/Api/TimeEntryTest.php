<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\BillingCategory;
use App\Entity\BillingProject;
use App\Entity\Client;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\TimeEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Time tracking (Harvest model): entries attach to a billing project + category
 * (which fixes the rate), on a single calendar day. Covers scoping, owner
 * stamping, derived duration + rate snapshot, the running-timer lifecycle, and
 * validation (required board/category, no overnight).
 */
class TimeEntryTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\BillingCategory')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\BillingProject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testMemberTracksTimeAndDurationAndRateAreDerived(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $spaceIri = $this->spaceIri($board);
        [$bpIri, $catIri] = $this->createBilling($board, 'Website', 'Development', 12000);

        $client = static::createClient();
        $client->loginUser($alice);

        $entry = $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'billingProject' => $bpIri,
                'category' => $catIri,
                'description' => 'Wiring the timer',
                'startedAt' => '2026-07-03T09:00:00+00:00',
                'endedAt' => '2026-07-03T10:30:00+00:00',
                'billable' => true,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(5400, $entry['durationSeconds'] ?? null);
        // Rate snapshotted from the category, not sent by the client.
        $this->assertSame(12000, $entry['rateAmount'] ?? null);
        $this->assertSame('USD', $entry['rateCurrency'] ?? null);

        $row = $this->entityManager->getRepository(TimeEntry::class)
            ->findOneBy(['description' => 'Wiring the timer']);
        $this->assertNotNull($row);
        $this->assertSame((string) $alice->getId(), (string) $row->getUser()?->getId());
    }

    public function testBillingProjectAndCategoryAreRequired(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $spaceIri = $this->spaceIri($board);

        $client = static::createClient();
        $client->loginUser($alice);
        $response = $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'startedAt' => '2026-07-03T09:00:00+00:00',
                'endedAt' => '2026-07-03T10:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
        $body = $response->getContent(false);
        $this->assertStringContainsString('billingProject', $body);
        $this->assertStringContainsString('category', $body);
    }

    public function testOvernightEntryIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $spaceIri = $this->spaceIri($board);
        [$bpIri, $catIri] = $this->createBilling($board, 'Website', 'Development', 10000);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'billingProject' => $bpIri,
                'category' => $catIri,
                'startedAt' => '2026-07-03T23:00:00+00:00',
                'endedAt' => '2026-07-04T01:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCategoryMustBelongToBillingProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $spaceIri = $this->spaceIri($board);
        [$bpIri] = $this->createBilling($board, 'Website', 'Development', 10000);
        // A category on a *different* billing project.
        [, $otherCatIri] = $this->createBilling($board, 'Mobile', 'Design', 9000);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'billingProject' => $bpIri,
                'category' => $otherCatIri,
                'startedAt' => '2026-07-03T09:00:00+00:00',
                'endedAt' => '2026-07-03T10:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testNonMemberCannotSeeEntries(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $board = $this->createProject($alice, 'Private');
        $spaceIri = $this->spaceIri($board);
        [$bpIri, $catIri] = $this->createBilling($board, 'Website', 'Development', 10000);

        $client = static::createClient();
        $client->loginUser($alice);
        $created = $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'billingProject' => $bpIri,
                'category' => $catIri,
                'startedAt' => '2026-07-03T09:00:00+00:00',
                'endedAt' => '2026-07-03T09:30:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $iri = $created['@id'];
        $this->assertIsString($iri);

        $client->loginUser($stranger);
        $list = $client->request('GET', '/time_entries?space=' . $spaceIri)->toArray();
        $this->assertSame(0, $list['totalItems'] ?? null);

        $client->request('GET', $iri);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testStartingASecondTimerStopsTheFirst(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $spaceIri = $this->spaceIri($board);
        [$bpIri, $catIri] = $this->createBilling($board, 'Website', 'Development', 10000);

        $client = static::createClient();
        $client->loginUser($alice);

        $first = $client->request('POST', '/time_entries', [
            'json' => ['space' => $spaceIri, 'billingProject' => $bpIri, 'category' => $catIri, 'startedAt' => '2026-07-03T09:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $firstIri = $first['@id'];
        $this->assertIsString($firstIri);

        $client->request('POST', '/time_entries', [
            'json' => ['space' => $spaceIri, 'billingProject' => $bpIri, 'category' => $catIri, 'startedAt' => '2026-07-03T11:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $first = $client->request('GET', $firstIri)->toArray();
        $this->assertNotNull($first['endedAt'] ?? null);

        $running = $client->request('GET', '/time_entries?space=' . $spaceIri . '&exists[endedAt]=false')->toArray();
        $this->assertSame(1, $running['totalItems'] ?? null);
    }

    public function testMemberCanListBillingProjectOptions(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $this->createBilling($board, 'Website', 'Development', 12000);
        $space = $board->getSpace();
        assert($space instanceof Space);

        $client = static::createClient();
        $client->loginUser($alice);
        $body = $client->request('GET', '/spaces/' . $space->getId() . '/billing-project-options')->toArray();

        $options = $body['options'];
        $this->assertIsArray($options);
        $this->assertCount(1, $options);
        $first = $options[0];
        $this->assertIsArray($first);
        $this->assertSame('Website', $first['name']);
        $categories = $first['categories'];
        $this->assertIsArray($categories);
        $this->assertCount(1, $categories);
        $cat = $categories[0];
        $this->assertIsArray($cat);
        $this->assertSame('Development', $cat['name']);
        $this->assertSame(12000, $cat['rateAmount']);
    }

    private function spaceIri(Board $board): string
    {
        $space = $board->getSpace();
        assert($space instanceof Space);

        return '/spaces/' . $space->getId();
    }

    /**
     * A client + a billing project (one category) in the board's space.
     *
     * @return array{0: string, 1: string} [billingProjectIri, categoryIri]
     */
    private function createBilling(Board $board, string $boardName, string $categoryName, int $rate): array
    {
        $space = $board->getSpace();
        assert($space instanceof Space);
        $owner = $board->getOwner();
        assert($owner instanceof User);

        $client = (new Client())->setSpace($space)->setName('Acme')->setCreatedBy($owner);
        $this->entityManager->persist($client);

        $category = (new BillingCategory())->setName($categoryName)->setRateAmount($rate);
        $billingProject = (new BillingProject())
            ->setSpace($space)
            ->setClient($client)
            ->setName($boardName)
            ->setCurrency('USD')
            ->setCreatedBy($owner);
        $billingProject->addCategory($category);
        $this->entityManager->persist($billingProject);
        $this->entityManager->flush();

        return ['/billing_projects/' . $billingProject->getId(), '/billing_categories/' . $category->getId()];
    }

    private function createProject(User $owner, string $title): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        $this->addBoardMember($board, $owner);
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
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
