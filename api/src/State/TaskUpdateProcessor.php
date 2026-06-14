<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Service\RecurrenceCalculator;
use App\Service\TaskActivityNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
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
        private Security $security,
        private TaskActivityNotifier $activity,
        private RecurrenceCalculator $recurrence,
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

        // Snapshot the persisted assignee ids before the flush so we can
        // tell who was *newly* assigned by this PATCH — the in-memory
        // collection has already been mutated, and `previous_data` shares
        // the same collection reference, so neither is trustworthy here.
        $previousAssigneeIds = $this->tasks->findAssigneeIdsFromDatabase($data);

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        $actor = $this->security->getUser();
        if ($actor instanceof User) {
            $newAssignees = [];
            foreach ($result->getAssignees() as $assignee) {
                $id = $assignee->getId();
                if (null !== $id && !in_array((string) $id, $previousAssigneeIds, true)) {
                    $newAssignees[] = $assignee;
                }
            }
            if ([] !== $newAssignees) {
                $this->activity->notifyAssigned($result, $actor, $newAssignees);
            }

            if ($wasIncomplete && $isNowComplete) {
                $this->activity->notifyCompleted($result, $actor);
            }
        }

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
        // re-narrow so phpstan sees the non-null shape below.
        $dueDate = $completed->getDueDate();
        $rule = $completed->getRecurrenceRule();
        if (null === $dueDate || null === $rule) {
            return;
        }

        // A count-based end treats `ends.count` as the number of occurrences
        // remaining in the series including the one just completed. When only
        // one remains, this WAS the last — spawn nothing. Otherwise the clone
        // carries the count decremented by one.
        $nextRule = $rule;
        $ends = $rule['ends'] ?? null;
        if (is_array($ends) && ($ends['type'] ?? null) === 'count') {
            $remaining = is_int($ends['count'] ?? null) ? $ends['count'] : 1;
            if ($remaining <= 1) {
                return;
            }
            $nextRule['ends'] = ['type' => 'count', 'count' => $remaining - 1];
        }

        $nextDue = $this->recurrence->nextDueDate($dueDate, $rule);
        if (null === $nextDue) {
            // Series ended (e.g. past the `until` bound).
            return;
        }

        $next = new Task();
        $next->setOwner($completed->getOwner());
        $next->setProject($completed->getProject());
        $next->setTitle($completed->getTitle());
        $next->setDescription($completed->getDescription());
        $next->setRecurrenceRule($nextRule);
        $next->setDueDate($nextDue);
        $next->setReminders($completed->getReminders());

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
}
