<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Focused coverage of {@see \App\Validator\ValidRecurrenceValidator} branches
 * — byDay, monthly weekday mode, and the `ends` end-conditions — beyond the
 * frequency/interval/due-date cases already in {@see TaskTest}.
 */
class RecurrenceValidationTest extends ApiTestCase
{
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testValidWeeklyByDayRecurrenceIsAccepted(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Weekly standup',
                'dueDate' => '2026-06-01T08:00:00+00:00',
                'recurrenceRule' => [
                    'frequency' => 'weekly',
                    'interval' => 1,
                    'byDay' => ['MO', 'WE', 'FR'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
    }

    public function testValidMonthlyWeekdayRecurrenceIsAccepted(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Last Friday of the month',
                'dueDate' => '2026-06-26T08:00:00+00:00',
                'recurrenceRule' => [
                    'frequency' => 'monthly',
                    'interval' => 1,
                    'monthlyMode' => 'weekday',
                    'byDay' => ['FR'],
                    'bySetPos' => -1,
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
    }

    public function testValidEndsCountRecurrenceIsAccepted(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Three dailies',
                'dueDate' => '2026-06-01T08:00:00+00:00',
                'recurrenceRule' => [
                    'frequency' => 'daily',
                    'interval' => 1,
                    'ends' => ['type' => 'count', 'count' => 3],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
    }

    public function testValidEndsUntilRecurrenceIsAccepted(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Until summer ends',
                'dueDate' => '2026-06-01T08:00:00+00:00',
                'recurrenceRule' => [
                    'frequency' => 'weekly',
                    'interval' => 2,
                    'ends' => ['type' => 'until', 'until' => '2026-09-21'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
    }

    public function testPatchToValidRecurrenceIsAccepted(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Will recur');
        $task->setDueDate(new \DateTimeImmutable('2026-06-01T08:00:00+00:00'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => [
                'recurrenceRule' => ['frequency' => 'monthly', 'interval' => 1],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $reloaded = $this->reloadTaskByTitle('Will recur');
        $this->assertSame(
            ['frequency' => 'monthly', 'interval' => 1],
            $reloaded->getRecurrenceRule(),
        );
    }

    public function testMissingFrequencyIsRejected(): void
    {
        // No `frequency` key → invalid shape branch.
        $this->assertRecurrenceRejected(['interval' => 1]);
    }

    public function testNonIntegerIntervalIsRejected(): void
    {
        // `interval` must be an int; a string trips the invalid-shape branch.
        $this->assertRecurrenceRejected(['frequency' => 'daily', 'interval' => '1']);
    }

    public function testInvalidByDayTokenIsRejected(): void
    {
        $this->assertRecurrenceRejected([
            'frequency' => 'weekly',
            'interval' => 1,
            'byDay' => ['XX'],
        ]);
    }

    public function testNonArrayByDayIsRejected(): void
    {
        $this->assertRecurrenceRejected([
            'frequency' => 'weekly',
            'interval' => 1,
            'byDay' => 'MO',
        ]);
    }

    public function testInvalidMonthlyModeIsRejected(): void
    {
        $this->assertRecurrenceRejected([
            'frequency' => 'monthly',
            'interval' => 1,
            'monthlyMode' => 'lunar',
        ]);
    }

    public function testMonthlyWeekdayMissingBySetPosIsRejected(): void
    {
        // weekday mode requires a single byDay AND a valid bySetPos.
        $this->assertRecurrenceRejected([
            'frequency' => 'monthly',
            'interval' => 1,
            'monthlyMode' => 'weekday',
            'byDay' => ['FR'],
        ]);
    }

    public function testMonthlyWeekdayOutOfRangeBySetPosIsRejected(): void
    {
        $this->assertRecurrenceRejected([
            'frequency' => 'monthly',
            'interval' => 1,
            'monthlyMode' => 'weekday',
            'byDay' => ['FR'],
            'bySetPos' => 6,
        ]);
    }

    public function testEndsCountZeroIsRejected(): void
    {
        $this->assertRecurrenceRejected([
            'frequency' => 'daily',
            'interval' => 1,
            'ends' => ['type' => 'count', 'count' => 0],
        ]);
    }

    public function testEndsUntilMalformedDateIsRejected(): void
    {
        $this->assertRecurrenceRejected([
            'frequency' => 'daily',
            'interval' => 1,
            'ends' => ['type' => 'until', 'until' => '21-09-2026'],
        ]);
    }

    public function testEndsUnknownTypeIsRejected(): void
    {
        $this->assertRecurrenceRejected([
            'frequency' => 'daily',
            'interval' => 1,
            'ends' => ['type' => 'forever'],
        ]);
    }

    public function testNonArrayEndsIsRejected(): void
    {
        $this->assertRecurrenceRejected([
            'frequency' => 'daily',
            'interval' => 1,
            'ends' => 'never',
        ]);
    }

    /**
     * POSTs a task carrying $rule (with a due date so only the rule shape can
     * trip the validator) and asserts a 422.
     *
     * @param array<string, mixed> $rule
     */
    private function assertRecurrenceRejected(array $rule): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Invalid recurrence',
                'dueDate' => '2026-06-01T08:00:00+00:00',
                'recurrenceRule' => $rule,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
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

    private function createTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    private function reloadTaskByTitle(string $title): Task
    {
        $this->entityManager->clear();
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['title' => $title]);
        assert($task instanceof Task);
        return $task;
    }
}
