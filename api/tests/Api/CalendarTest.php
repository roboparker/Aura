<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers GET /calendar (occurrence boardion, space scoping) and
 * POST /tasks/{id}/detach-occurrence (issue #442).
 */
class CalendarTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCalendarRequiresAuth(): void
    {
        static::createClient()->request('GET', '/calendar');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCalendarReturnsProjectAndStandaloneTasksInRange(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Launch');
        $space = $board->getSpace();
        assert($space instanceof Space);

        $this->createTask($alice, 'In range', $board, '2026-06-15T09:00:00+00:00');
        $this->createTask($alice, 'Out of range', $board, '2026-07-20T09:00:00+00:00');
        // Standalone (no board) — shows because it's the owner's personal space.
        $this->createTask($alice, 'Standalone', null, '2026-06-16T09:00:00+00:00');

        $client = static::createClient();
        $client->loginUser($alice);
        $response = $client->request('GET', $this->url($space, '2026-06-01', '2026-06-30'));
        $this->assertResponseIsSuccessful();

        $titles = $this->entryTitles($response->toArray());
        $this->assertContains('In range', $titles);
        $this->assertContains('Standalone', $titles);
        $this->assertNotContains('Out of range', $titles);
    }

    public function testCalendarExpandsRecurringSeries(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Launch');
        $space = $board->getSpace();
        assert($space instanceof Space);

        $task = $this->createTask($alice, 'Daily standup', $board, '2026-06-15T09:00:00+00:00');
        $task->setRecurrenceRule([
            'frequency' => 'daily',
            'interval' => 1,
            'ends' => ['type' => 'count', 'count' => 3],
        ]);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $response = $client->request('GET', $this->url($space, '2026-06-01', '2026-06-30'));
        $this->assertResponseIsSuccessful();

        $dates = $this->occurrenceDatesFor($response->toArray(), 'Daily standup');
        $this->assertSame(['2026-06-15', '2026-06-16', '2026-06-17'], $dates);
    }

    public function testCalendarProjectFilterScopesToOneProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $one = $this->createProject($alice, 'Alpha');
        $two = $this->createProject($alice, 'Beta');
        $space = $one->getSpace();
        assert($space instanceof Space);

        $this->createTask($alice, 'Alpha task', $one, '2026-06-15T09:00:00+00:00');
        $this->createTask($alice, 'Beta task', $two, '2026-06-16T09:00:00+00:00');
        $this->createTask($alice, 'Loose task', null, '2026-06-17T09:00:00+00:00');

        $client = static::createClient();
        $client->loginUser($alice);
        $response = $client->request(
            'GET',
            $this->url($space, '2026-06-01', '2026-06-30') . '&board=' . $one->getId(),
        );
        $this->assertResponseIsSuccessful();

        $titles = $this->entryTitles($response->toArray());
        $this->assertContains('Alpha task', $titles);
        $this->assertNotContains('Beta task', $titles);
        $this->assertNotContains('Loose task', $titles);
    }

    public function testCalendarHidesForNonMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $board = $this->createProject($alice, 'Launch');
        $space = $board->getSpace();
        assert($space instanceof Space);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', $this->url($space, '2026-06-01', '2026-06-30'));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDetachOccurrenceSplitsTheSeries(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Launch');
        $space = $board->getSpace();
        assert($space instanceof Space);

        $task = $this->createTask($alice, 'Standup', $board, '2026-06-15T09:00:00+00:00');
        $task->setRecurrenceRule(['frequency' => 'daily', 'interval' => 1]);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);

        // Detach the 06-16 occurrence, moving that copy to 06-20.
        $client->request('POST', '/tasks/' . $task->getId() . '/detach-occurrence', [
            'json' => ['date' => '2026-06-16', 'dueDate' => '2026-06-20'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $response = $client->request('GET', $this->url($space, '2026-06-15', '2026-06-21'));
        $this->assertResponseIsSuccessful();
        $dates = $this->occurrenceDatesFor($response->toArray(), 'Standup');

        // 06-16 is now an EXDATE (gone from the series); the detached copy
        // lands on 06-20; the rest of the series still boards.
        $this->assertContains('2026-06-15', $dates);
        $this->assertNotContains('2026-06-16', $dates);
        $this->assertContains('2026-06-20', $dates);
    }

    public function testDetachRejectsNonRecurringTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Launch');
        $task = $this->createTask($alice, 'One-off', $board, '2026-06-15T09:00:00+00:00');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/' . $task->getId() . '/detach-occurrence', [
            'json' => ['date' => '2026-06-15', 'dueDate' => '2026-06-20'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    private function url(Space $space, string $start, string $end): string
    {
        return '/calendar?' . http_build_query([
            'space' => '/spaces/' . $space->getId(),
            'start' => $start,
            'end' => $end,
        ]);
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return list<string>
     */
    private function entryTitles(array $body): array
    {
        $entries = $body['entries'] ?? null;
        $this->assertIsArray($entries);
        $titles = [];
        foreach ($entries as $entry) {
            $this->assertIsArray($entry);
            $title = $entry['title'] ?? null;
            if (is_string($title)) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * Ascending occurrence dates for the entries of a given task title.
     *
     * @param array<int|string, mixed> $body
     *
     * @return list<string>
     */
    private function occurrenceDatesFor(array $body, string $title): array
    {
        $entries = $body['entries'] ?? null;
        $this->assertIsArray($entries);
        $dates = [];
        foreach ($entries as $entry) {
            $this->assertIsArray($entry);
            if (($entry['title'] ?? null) !== $title) {
                continue;
            }
            $date = $entry['occurrenceDate'] ?? null;
            if (is_string($date)) {
                $dates[] = $date;
            }
        }
        sort($dates);

        return $dates;
    }

    private function createUser(string $email, string $role = 'ROLE_USER'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles([$role]);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
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

    private function createTask(
        User $owner,
        string $title,
        ?Board $board,
        string $dueDate,
    ): Task {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        $task->setBoard($board);
        $task->setDueDate(new \DateTimeImmutable($dueDate));
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }
}
