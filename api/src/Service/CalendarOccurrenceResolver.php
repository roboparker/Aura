<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Projects a space's due-dated tasks onto a date window, expanding recurring
 * series into individual occurrences.
 *
 * Extracted from {@see \App\Controller\CalendarController} when the MCP
 * calendar tool landed. It returns task + occurrence pairs rather than
 * rendered entries, because the two callers legitimately serialize
 * differently — the REST controller emits IRIs and nested board/assignee/tag
 * objects for the calendar UI, while MCP emits a flat, model-readable shape.
 * Sharing the *projection* while letting each own its *presentation* is the
 * seam that matters: the recurrence rules are what must never diverge.
 */
final class CalendarOccurrenceResolver
{
    /** Widest window we'll project (a 6-week month grid is 42 days). */
    public const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecurrenceCalculator $recurrence,
    ) {
    }

    /**
     * Every occurrence falling inside the window.
     *
     *  - A non-recurring task contributes one entry on its due date.
     *  - An *incomplete* recurring task projects its whole series across the
     *    window (minus its EXDATE `recurrenceExceptions`) — the model only
     *    materialises the next instance on completion, so the calendar has to
     *    project the upcoming ones itself.
     *  - A *completed* recurring task contributes only its single done
     *    occurrence; its successor (spawned on completion) projects the rest,
     *    so a series is never double-counted.
     *
     * @return list<array{task: Task, occurrence: \DateTimeImmutable, recurring: bool}>
     */
    public function occurrences(
        Space $space,
        User $user,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        ?string $boardId = null,
    ): array {
        $entries = [];

        foreach ($this->fetchTasks($space, $user, $rangeStart, $rangeEnd, $boardId) as $task) {
            $due = $task->getDueDate();
            if (null === $due) {
                continue;
            }

            $rule = $task->getRecurrenceRule();
            $isLiveSeries = null !== $rule && null === $task->getCompletedOn();

            if (!$isLiveSeries) {
                if ($due >= $rangeStart && $due <= $rangeEnd) {
                    $entries[] = ['task' => $task, 'occurrence' => $due, 'recurring' => false];
                }
                continue;
            }

            $occurrences = $this->recurrence->occurrencesInRange(
                $due,
                $rule,
                $rangeStart,
                $rangeEnd,
                $task->getRecurrenceExceptions(),
            );
            foreach ($occurrences as $occurrence) {
                $entries[] = ['task' => $task, 'occurrence' => $occurrence, 'recurring' => true];
            }
        }

        return $entries;
    }

    /**
     * Due-dated tasks in scope for the window: anything that could contribute
     * an occurrence — a recurring anchor on/before the end, or a non-recurring
     * task inside the window.
     *
     * @return list<Task>
     */
    private function fetchTasks(
        Space $space,
        User $user,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        ?string $boardId,
    ): array {
        $qb = $this->em->getRepository(Task::class)->createQueryBuilder('t')
            ->leftJoin('t.board', 'p')
            ->andWhere('t.dueDate IS NOT NULL')
            ->andWhere('t.dueDate <= :end')
            ->andWhere('t.recurrenceRule IS NOT NULL OR t.dueDate >= :start')
            ->setParameter('start', $rangeStart)
            ->setParameter('end', $rangeEnd)
            ->setParameter('space', $space);

        if (null !== $boardId) {
            // Board-tab calendar: just that board's tasks, still scoped to
            // the space so a foreign board id resolves to nothing.
            $qb->andWhere('p.space = :space AND p.id = :boardId')
                ->setParameter('boardId', $boardId);

            /** @var list<Task> $scoped */
            $scoped = $qb->getQuery()->getResult();

            return $scoped;
        }

        // Standalone (board-less) tasks are owner-only and only belong on the
        // owner's personal-space calendar.
        $personal = $space->getIsPersonal()
            && true === $space->getCreatedBy()?->getId()?->equals($user->getId());
        if ($personal) {
            $qb->andWhere('p.space = :space OR (t.board IS NULL AND t.owner = :owner)')
                ->setParameter('owner', $user);
        } else {
            $qb->andWhere('p.space = :space');
        }

        /** @var list<Task> $tasks */
        $tasks = $qb->getQuery()->getResult();

        return $tasks;
    }
}
