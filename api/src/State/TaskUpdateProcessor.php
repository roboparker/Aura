<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Task;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wraps the default ORM persist processor on `PATCH /tasks/{id}` so we can
 * react to the task being marked complete. When a recurring task is
 * completed for the first time, we clone it with the next due date so the
 * next occurrence appears in the user's list automatically.
 *
 * The "completed" trigger is the `completedOn` timestamp transitioning from
 * null to non-null. API Platform passes the pre-update entity state in
 * `$context['previous_data']`, which we use to detect the transition without
 * round-tripping the database.
 *
 * @implements ProcessorInterface<Task, Task>
 */
final class TaskUpdateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Task, Task> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private EntityManagerInterface $em,
        private TaskRepository $tasks,
    ) {
    }

    /**
     * @param Task $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Task
    {
        $previous = $context['previous_data'] ?? null;
        $wasIncomplete = $previous instanceof Task && null === $previous->getCompletedOn();
        $isNowComplete = null !== $data->getCompletedOn();
        $shouldRecur = $wasIncomplete
            && $isNowComplete
            && null !== $data->getRecurrenceRule()
            && null !== $data->getDueDate();

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        if ($shouldRecur) {
            $this->createNextOccurrence($result);
        }

        return $result;
    }

    /**
     * Clone the just-completed task into a fresh, incomplete row whose
     * dueDate is advanced per the recurrence rule. Tags, assignees, project,
     * description, and title carry over. Position goes to the top of the
     * owner's list to mirror normal task creation.
     */
    private function createNextOccurrence(Task $completed): void
    {
        // Caller only invokes us after asserting both fields are set —
        // re-narrow so phpstan can see the non-null shape on the
        // advanceDueDate call below.
        $dueDate = $completed->getDueDate();
        $rule = $completed->getRecurrenceRule();
        if (null === $dueDate || null === $rule) {
            return;
        }
        $next = new Task();
        $next->setOwner($completed->getOwner());
        $next->setProject($completed->getProject());
        $next->setTitle($completed->getTitle());
        $next->setDescription($completed->getDescription());
        $next->setRecurrenceRule($rule);
        $next->setDueDate($this->advanceDueDate($dueDate, $rule));

        foreach ($completed->getTags() as $tag) {
            $next->addTag($tag);
        }
        foreach ($completed->getAssignees() as $assignee) {
            $next->addAssignee($assignee);
        }

        $owner = $completed->getOwner();
        if (null !== $owner) {
            $min = $this->tasks->findMinPositionForOwner($owner);
            $next->setPosition(null === $min ? 0 : $min - 1);
        }

        $this->em->persist($next);
        $this->em->flush();
    }

    /**
     * Compute the next dueDate from the previous one + recurrence rule.
     * Advances off the original `dueDate` (not "now") so missing a deadline
     * doesn't shift the schedule forward.
     *
     * @param array{frequency: string, interval: int} $rule
     */
    private function advanceDueDate(\DateTimeImmutable $current, array $rule): \DateTimeImmutable
    {
        $interval = max(1, (int) $rule['interval']);
        // \DateTimeImmutable::modify is calendar-aware: "+1 month" off Jan 31
        // becomes Mar 3 (skipping Feb), which is the standard PHP behaviour
        // and matches what users intuitively expect for monthly recurrence.
        return match ($rule['frequency']) {
            'daily' => $current->modify(sprintf('+%d days', $interval)),
            'weekly' => $current->modify(sprintf('+%d weeks', $interval)),
            'monthly' => $current->modify(sprintf('+%d months', $interval)),
            'yearly' => $current->modify(sprintf('+%d years', $interval)),
            default => $current,
        };
    }
}
