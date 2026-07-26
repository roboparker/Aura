<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Automation\AutomationContext;
use App\Automation\AutomationEvents;
use App\Automation\AutomationRunner;
use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\Board;
use App\Entity\Comment;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Task;
use App\Entity\TaskSection;
use App\Entity\User;
use App\Message\PruneAutomationRuns;
use App\Message\SweepDueTasks;
use App\MessageHandler\PruneAutomationRunsHandler;
use App\MessageHandler\SweepDueTasksHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Every trigger, condition, and action actually doing its job (#764).
 *
 * The other automation tests cover the engine and its guard rails; this covers
 * the catalog itself — the success paths, which is where a wrong comparison or a
 * misread field would quietly produce a rule that does the wrong thing rather
 * than one that visibly fails.
 */
class AutomationCatalogTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;
    private AutomationRunner $runner;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->runner = static::getContainer()->get(AutomationRunner::class);

        $tearDownOrder = [
            'App\Entity\AutomationRun',
            'App\Entity\Automation',
            'App\Entity\Comment',
            'App\Entity\Task',
            'App\Entity\TaskSection',
            'App\Entity\Board',
            'App\Entity\SpaceMembership',
            'App\Entity\Space',
            'App\Entity\User',
        ];
        foreach ($tearDownOrder as $entity) {
            $this->entityManager->createQuery('DELETE FROM ' . $entity)->execute();
        }
    }

    // ---- actions ---------------------------------------------------------

    public function testAssignAddsASpaceMember(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board);

        $run = $this->fire($board, $owner, $task, [
            'type' => 'task.assign',
            'config' => ['user' => (string) $owner->getId()],
        ]);

        $this->assertSame(AutomationRun::STATUS_APPLIED, $run->getStatus());
        $this->assertCount(1, $task->getAssignees());
    }

    public function testAssigningSomeoneAlreadyOnTheTaskIsANoOp(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board);
        $task->addAssignee($owner);
        $this->entityManager->flush();

        $run = $this->fire($board, $owner, $task, [
            'type' => 'task.assign',
            'config' => ['user' => (string) $owner->getId()],
        ]);

        $this->assertCount(1, $task->getAssignees(), 'No duplicate assignee.');
        $this->assertStringContainsString('already assigned', $run->getSteps()[0]['detail']);
    }

    public function testUnassignAllClearsEveryone(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $second = $this->makeUser('second@example.com');
        $task = $this->makeTask($owner, $board);
        $task->addAssignee($owner);
        $task->addAssignee($second);
        $this->entityManager->flush();

        $run = $this->fire($board, $owner, $task, ['type' => 'task.unassign_all', 'config' => []]);

        $this->assertCount(0, $task->getAssignees());
        $this->assertStringContainsString('2 assignee', $run->getSteps()[0]['detail']);
    }

    public function testUnassignAllOnAnUnassignedTaskSaysSo(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board);

        $run = $this->fire($board, $owner, $task, ['type' => 'task.unassign_all', 'config' => []]);

        $this->assertStringContainsString('nobody was assigned', $run->getSteps()[0]['detail']);
    }

    public function testSetDueDateIsRelativeToNow(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board);

        $this->fire($board, $owner, $task, [
            'type' => 'task.set_due_date',
            'config' => ['mode' => 'in_days', 'days' => 3],
        ]);

        $expected = (new \DateTimeImmutable('today'))->modify('+3 days');
        $this->assertSame($expected->format('Y-m-d'), $task->getDueDate()?->format('Y-m-d'));
    }

    public function testSetDueDateCanClearIt(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board);
        $task->setDueDate(new \DateTimeImmutable('+1 day'));
        $this->entityManager->flush();

        $this->fire($board, $owner, $task, [
            'type' => 'task.set_due_date',
            'config' => ['mode' => 'clear'],
        ]);

        $this->assertNull($task->getDueDate());
    }

    public function testMoveToSectionMovesWithinTheBoard(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $section = $this->makeSection($board, 'Done');
        $task = $this->makeTask($owner, $board);

        $run = $this->fire($board, $owner, $task, [
            'type' => 'task.move_section',
            'config' => ['section' => (string) $section->getId()],
        ]);

        $this->assertSame($section, $task->getSection());
        $this->assertStringContainsString('Done', $run->getSteps()[0]['detail']);
    }

    public function testMovingToTheSectionItIsAlreadyInIsANoOp(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $section = $this->makeSection($board, 'Done');
        $task = $this->makeTask($owner, $board);
        $task->setSection($section);
        $this->entityManager->flush();

        $run = $this->fire($board, $owner, $task, [
            'type' => 'task.move_section',
            'config' => ['section' => (string) $section->getId()],
        ]);

        $this->assertStringContainsString('already in', $run->getSteps()[0]['detail']);
    }

    /** A rule-written comment must say it was automated. */
    public function testCommentIsAttributedAndMarkedAutomated(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board);

        $this->fire($board, $owner, $task, [
            'type' => 'task.comment',
            'config' => ['body' => 'Please review'],
        ], ruleName: 'Review nudge');

        $comments = $this->entityManager->getRepository(Comment::class)->findAll();
        $this->assertCount(1, $comments);
        $this->assertSame($owner, $comments[0]->getAuthor());
        $this->assertStringContainsString('Please review', $comments[0]->getBody());
        // Words nobody typed must not appear under a name without a marker.
        $this->assertStringContainsString('Posted automatically', $comments[0]->getBody());
        $this->assertStringContainsString('Review nudge', $comments[0]->getBody());
    }

    public function testAddTagReusesAnExistingTagCaseInsensitively(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board);

        $this->fire($board, $owner, $task, ['type' => 'task.add_tag', 'config' => ['tag' => 'Urgent']]);
        $this->assertCount(1, $task->getTags());

        // A second rule adding the lower-case spelling must not create a twin.
        $other = $this->makeTask($owner, $board, 'Second');
        $this->fire($board, $owner, $other, ['type' => 'task.add_tag', 'config' => ['tag' => 'urgent']]);
        $this->assertCount(1, $other->getTags());

        $firstTag = $task->getTags()->first();
        $secondTag = $other->getTags()->first();
        $this->assertNotFalse($firstTag);
        $this->assertNotFalse($secondTag);
        $this->assertSame(
            (string) $firstTag->getId(),
            (string) $secondTag->getId(),
            'Both tasks should share one tag row.',
        );
    }

    // ---- conditions ------------------------------------------------------

    public function testIsAssignedGatesBothWays(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board);

        // Unassigned: "unassigned" passes, "assigned" doesn't.
        $this->assertTrue($this->gated($board, $owner, $task, 'task.is_assigned', ['state' => 'unassigned']));
        $this->assertFalse($this->gated($board, $owner, $task, 'task.is_assigned', ['state' => 'assigned']));

        $task->addAssignee($owner);
        $this->entityManager->flush();

        $this->assertTrue($this->gated($board, $owner, $task, 'task.is_assigned', ['state' => 'assigned']));
        $this->assertFalse($this->gated($board, $owner, $task, 'task.is_assigned', ['state' => 'unassigned']));
    }

    public function testIsOverdueIgnoresUndatedAndCompletedTasks(): void
    {
        [$owner, $space, $board] = $this->scaffold();

        $undated = $this->makeTask($owner, $board, 'No date');
        // An undated task can't be late for a deadline it doesn't have.
        $this->assertFalse($this->gated($board, $owner, $undated, 'task.is_overdue', []));

        $overdue = $this->makeTask($owner, $board, 'Late');
        $overdue->setDueDate(new \DateTimeImmutable('-2 days'));
        $this->entityManager->flush();
        $this->assertTrue($this->gated($board, $owner, $overdue, 'task.is_overdue', []));

        $done = $this->makeTask($owner, $board, 'Late but done');
        $done->setDueDate(new \DateTimeImmutable('-2 days'));
        $done->setCompletedOn(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->assertFalse($this->gated($board, $owner, $done, 'task.is_overdue', []));
    }

    public function testDueWithinCountsOverdueAsDue(): void
    {
        [$owner, $space, $board] = $this->scaffold();

        $soon = $this->makeTask($owner, $board, 'Soon');
        $soon->setDueDate(new \DateTimeImmutable('+2 days'));
        $this->entityManager->flush();
        $this->assertTrue($this->gated($board, $owner, $soon, 'task.due_within', ['days' => 7]));
        $this->assertFalse($this->gated($board, $owner, $soon, 'task.due_within', ['days' => 1]));

        // "Anything due this week" should not skip yesterday's.
        $late = $this->makeTask($owner, $board, 'Yesterday');
        $late->setDueDate(new \DateTimeImmutable('-1 day'));
        $this->entityManager->flush();
        $this->assertTrue($this->gated($board, $owner, $late, 'task.due_within', ['days' => 7]));

        $undated = $this->makeTask($owner, $board, 'Undated');
        $this->assertFalse($this->gated($board, $owner, $undated, 'task.due_within', ['days' => 30]));
    }

    // ---- triggers --------------------------------------------------------

    public function testMovedToSectionOnlyFiresOnAnActualMove(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $section = $this->makeSection($board, 'Review');
        $task = $this->makeTask($owner, $board);

        $rule = $this->makeRule($board, $owner, AutomationEvents::TASK_UPDATED, [
            'nodes' => [
                [
                    'id' => 't',
                    'kind' => 'trigger',
                    'type' => 'task.moved_to_section',
                    'config' => ['section' => (string) $section->getId()],
                ],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'reviewing']],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ]);

        // No section change in the snapshot — an edit to a task already sitting
        // in Review must not re-fire the rule.
        $unchanged = new AutomationContext(
            task: $task,
            event: AutomationEvents::TASK_UPDATED,
            before: ['section' => (string) $section->getId()],
            after: ['section' => (string) $section->getId()],
        );
        $this->assertNull($this->runner->run($rule, $unchanged));

        $moved = new AutomationContext(
            task: $task,
            event: AutomationEvents::TASK_UPDATED,
            before: ['section' => null],
            after: ['section' => (string) $section->getId()],
        );
        $run = $this->runner->run($rule, $moved);
        $this->assertNotNull($run);
        $this->assertSame(AutomationRun::STATUS_APPLIED, $run->getStatus());
    }

    // ---- scheduled jobs --------------------------------------------------

    public function testTheDueSweepFiresForNewlyOverdueTasksOnly(): void
    {
        [$owner, $space, $board] = $this->scaffold();

        $this->makeRule($board, $owner, AutomationEvents::TASK_DUE, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.due_passed', 'config' => []],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'overdue']],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ]);

        // Just tipped over: inside the lookback window.
        $fresh = $this->makeTask($owner, $board, 'Just late');
        $fresh->setDueDate((new \DateTimeImmutable('today'))->modify('-1 hour'));

        // Long overdue: outside the window, or the rule would re-fire hourly
        // for the rest of this task's life.
        $stale = $this->makeTask($owner, $board, 'Long late');
        $stale->setDueDate(new \DateTimeImmutable('-30 days'));
        $this->entityManager->flush();

        $handler = static::getContainer()->get(SweepDueTasksHandler::class);
        $handler(new SweepDueTasks());
        $this->entityManager->flush();

        $this->assertCount(1, $fresh->getTags(), 'The newly overdue task should be tagged.');
        $this->assertCount(0, $stale->getTags(), 'A long-overdue task must not re-fire.');
    }

    public function testThePrunerDropsOldRunsAndKeepsRecentOnes(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board);
        $rule = $this->makeRule($board, $owner, AutomationEvents::TASK_COMPLETED, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'x']],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ]);

        $old = $this->makeRun($rule, $task);
        $recent = $this->makeRun($rule, $task);
        $this->backdateRun($old, '-400 days');

        $handler = static::getContainer()->get(PruneAutomationRunsHandler::class);
        $handler(new PruneAutomationRuns());

        $remaining = $this->entityManager->getRepository(AutomationRun::class)->findAll();
        $this->assertCount(1, $remaining);
        $this->assertSame((string) $recent->getId(), (string) $remaining[0]->getId());
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Runs a one-action rule and returns the run.
     *
     * @param array{type: string, config: array<string, mixed>} $action
     */
    private function fire(
        Board $board,
        User $owner,
        Task $task,
        array $action,
        string $ruleName = 'Rule',
    ): AutomationRun {
        $rule = $this->makeRule($board, $owner, AutomationEvents::TASK_COMPLETED, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'a', 'kind' => 'action', 'type' => $action['type'], 'config' => $action['config']],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ], $ruleName);

        $run = $this->runner->run(
            $rule,
            new AutomationContext(task: $task, event: AutomationEvents::TASK_COMPLETED),
        );
        $this->assertNotNull($run);
        $this->entityManager->flush();

        return $run;
    }

    /**
     * Whether a condition let its branch through.
     *
     * @param array<string, mixed> $config
     */
    private function gated(
        Board $board,
        User $owner,
        Task $task,
        string $conditionType,
        array $config,
    ): bool {
        $rule = $this->makeRule($board, $owner, AutomationEvents::TASK_COMPLETED, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'c', 'kind' => 'condition', 'type' => $conditionType, 'config' => $config],
            ],
            'edges' => [['from' => 't', 'to' => 'c']],
        ]);

        $run = $this->runner->run(
            $rule,
            new AutomationContext(task: $task, event: AutomationEvents::TASK_COMPLETED),
        );
        $this->assertNotNull($run);

        return 'passed' === $run->getSteps()[0]['outcome'];
    }

    /** @param array<string, mixed> $graph */
    private function makeRule(
        Board $board,
        User $author,
        string $event,
        array $graph,
        string $name = 'Rule',
    ): Automation {
        $rule = (new Automation())
            ->setBoard($board)
            ->setName($name)
            ->setTriggerEvent($event)
            ->setGraph($graph)
            ->setCreatedBy($author);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $rule;
    }

    private function makeRun(Automation $rule, Task $task): AutomationRun
    {
        $run = (new AutomationRun())->setAutomation($rule)->setTask($task);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function backdateRun(AutomationRun $run, string $modifier): void
    {
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            'UPDATE automation_run SET ran_at = :when WHERE id = :id',
            [
                'when' => (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s'),
                'id' => (string) $run->getId(),
            ],
        );
    }

    /** @return array{0: User, 1: Space, 2: Board} */
    private function scaffold(): array
    {
        $owner = $this->makeUser('owner@example.com');
        $space = (new Space())->setName('Studio')->setCreatedBy($owner);
        $this->entityManager->persist($space);
        $space->addUserMembership((new SpaceMembership())->setUser($owner)->setRole(Space::ROLE_ADMIN));
        $this->entityManager->flush();

        $board = (new Board())->setTitle('Main')->setSpace($space)->setOwner($owner);
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return [$owner, $space, $board];
    }

    private function makeSection(Board $board, string $title): TaskSection
    {
        $section = (new TaskSection())->setBoard($board)->setTitle($title);
        $section->syncSpaceFromBoard();
        $this->entityManager->persist($section);
        $this->entityManager->flush();

        return $section;
    }

    private function makeTask(User $owner, Board $board, string $title = 'A task'): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setBoard($board);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
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
