<?php

namespace App\MessageHandler;

use App\Automation\AutomationDispatcher;
use App\Automation\AutomationEvents;
use App\Automation\TaskSnapshot;
use App\Entity\Task;
use App\Message\SweepDueTasks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fires `task.due` for tasks that have just gone overdue (#764).
 *
 * Nothing happens in a request when a date passes, so this goes looking.
 *
 * **Only tasks that became overdue since the last sweep.** Selecting every
 * overdue task would re-fire the rule hourly for the rest of the task's life —
 * the classic way an automation feature becomes a notification firehose. The
 * window is the sweep interval plus a margin, so a missed run catches up
 * without replaying weeks of history.
 */
#[AsMessageHandler]
final class SweepDueTasksHandler
{
    /**
     * How far back to look. Wider than the hourly cadence so a skipped run
     * (deploy, worker restart) still catches its tasks on the next pass.
     */
    private const LOOKBACK_HOURS = 3;

    /** Bound on one sweep, so a backlog can't monopolise the worker. */
    private const MAX_PER_SWEEP = 500;

    public function __construct(
        private EntityManagerInterface $em,
        private AutomationDispatcher $automations,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SweepDueTasks $message): void
    {
        $now = new \DateTimeImmutable('today');
        $since = $now->modify(sprintf('-%d hours', self::LOOKBACK_HOURS));

        /** @var list<Task> $tasks */
        $tasks = $this->em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->innerJoin('t.board', 'b')
            ->where('t.completedOn IS NULL')
            ->andWhere('t.dueDate < :now')
            ->andWhere('t.dueDate >= :since')
            ->setParameter('now', $now)
            ->setParameter('since', $since)
            ->setMaxResults(self::MAX_PER_SWEEP)
            ->getQuery()
            ->getResult();

        $dispatched = 0;
        foreach ($tasks as $task) {
            // The dispatcher skips boards with no matching rule, so this is
            // cheap on the common case of a board that automates nothing.
            $this->automations->dispatch($task, AutomationEvents::TASK_DUE, [], TaskSnapshot::of($task));
            ++$dispatched;
        }

        if ($dispatched > 0) {
            $this->logger->info('Swept newly overdue tasks for automations.', ['tasks' => $dispatched]);
        }
    }
}
