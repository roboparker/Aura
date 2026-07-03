<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Task;
use App\Message\SyncTaskToCalendar;
use App\Repository\TaskRepository;
use App\Security\AuthenticatedUserResolver;
use App\Service\TaskActivityNotifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sets the owner of a new Task to the currently authenticated user and
 * places it at the top of their list (one slot above the current minimum
 * position). Negative positions are intentional — they let new tasks insert
 * at the top without rewriting every existing row.
 *
 * @implements ProcessorInterface<Task, Task>
 */
final class TaskOwnerProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Task, Task> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
        private TaskRepository $tasks,
        private TaskActivityNotifier $activity,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param Task $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Task
    {
        $user = $this->auth->requireUser('create a task');

        $data->setOwner($user);

        // In a personal ("Private") space everything belongs to the one
        // member, so a new task with no assignees defaults to its creator —
        // saving the repetitive self-assign on a solo board. Shared spaces
        // keep starting unassigned so the team picks who owns the work.
        $space = $data->getProject()?->getSpace();
        if ($data->getAssignees()->isEmpty() && true === $space?->getIsPersonal()) {
            $data->addAssignee($user);
        }

        $min = $this->tasks->findMinPositionForOwner($user);
        $data->setPosition(null === $min ? 0 : $min - 1);

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        // On create, every assignee is freshly assigned.
        $this->activity->notifyAssigned($result, $user, $result->getAssignees());

        // Push to the owner's connected calendar (#582). The handler no-ops
        // when the owner hasn't linked an account, so a dated task is enough.
        $id = $result->getId();
        if (null !== $result->getDueDate() && null !== $id) {
            $this->bus->dispatch(SyncTaskToCalendar::upsert((string) $id, (string) $user->getId()));
        }

        return $result;
    }
}
