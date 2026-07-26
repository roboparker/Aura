<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Automation\AutomationDispatcher;
use App\Automation\AutomationEvents;
use App\Automation\TaskSnapshot;
use App\Entity\CalendarEventLink;
use App\Entity\Task;
use App\Entity\User;
use App\Message\SyncTaskToCalendar;
use App\Repository\TaskRepository;
use App\Service\RecurrenceCalculator;
use App\Service\TaskActivityNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private MessageBusInterface $bus,
        private AutomationDispatcher $automations,
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

        $successor = null;
        if ($shouldRecur) {
            $successor = $this->createNextOccurrence($result);
        }

        // Keep the owner's connected calendar (#582) in step.
        $owner = $result->getOwner();
        if ($owner instanceof User) {
            if ($successor instanceof Task) {
                // The recurring series continues on the spawned occurrence,
                // which inherited the calendar event link(s) — updating it moves
                // the native series forward. The completed task no longer owns an
                // event, so we deliberately don't dispatch for it (#582 Phase D4).
                $successorId = $successor->getId();
                if (null !== $successorId) {
                    $this->bus->dispatch(SyncTaskToCalendar::upsert((string) $successorId, (string) $owner->getId()));
                }
            } else {
                // Dispatch when the task is (or just stopped being) dated — the
                // latter lets the handler remove an event whose task lost its due
                // date. Covers title edits, reschedules, completion, and a
                // recurring series that has now ended.
                $previousDue = $previous instanceof Task ? $previous->getDueDate() : null;
                $id = $result->getId();
                if ((null !== $result->getDueDate() || null !== $previousDue) && null !== $id) {
                    $this->bus->dispatch(SyncTaskToCalendar::upsert((string) $id, (string) $owner->getId()));
                }
            }
        }

        // Board rules (#764). Enqueued, never run inline — a rule can do real
        // work and none of it belongs on the latency path of a checkbox.
        //
        // The completion transition gets its own event so a "when completed"
        // rule can't also fire on every later edit of an already-done task;
        // everything else is a generic update, narrowed by the trigger.
        $before = $previous instanceof Task ? TaskSnapshot::of($previous) : [];
        $after = TaskSnapshot::of($result);
        $this->automations->dispatch(
            $result,
            $wasIncomplete && $isNowComplete
                ? AutomationEvents::TASK_COMPLETED
                : AutomationEvents::TASK_UPDATED,
            $before,
            $after,
            $actor instanceof User ? $actor : null,
        );

        return $result;
    }

    /**
     * Clone the just-completed task into a fresh, incomplete row whose
     * dueDate is advanced per the recurrence rule. Tags, assignees, board,
     * description, and title carry over. Position goes to the top of the
     * owner's list to mirror normal task creation. Returns the spawned task
     * (or null when the series has ended).
     */
    private function createNextOccurrence(Task $completed): ?Task
    {
        // Caller only invokes us after asserting both fields are set —
        // re-narrow so phpstan sees the non-null shape below.
        $dueDate = $completed->getDueDate();
        $rule = $completed->getRecurrenceRule();
        if (null === $dueDate || null === $rule) {
            return null;
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
                return null;
            }
            $nextRule['ends'] = ['type' => 'count', 'count' => $remaining - 1];
        }

        $nextDue = $this->recurrence->nextDueDate($dueDate, $rule);
        if (null === $nextDue) {
            // Series ended (e.g. past the `until` bound).
            return null;
        }

        $next = new Task();
        $next->setOwner($completed->getOwner());
        $next->setBoard($completed->getBoard());
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

        // Hand any calendar event link(s) to the successor so the native
        // recurring series continues on it instead of the completed task
        // spawning a duplicate series (#582 Phase D4).
        foreach ($this->em->getRepository(CalendarEventLink::class)->findBy(['task' => $completed]) as $link) {
            $link->setTask($next);
        }

        $this->em->flush();

        return $next;
    }
}
