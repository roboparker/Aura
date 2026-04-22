<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

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
        private Security $security,
        private TaskRepository $tasks,
    ) {
    }

    /**
     * @param Task $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Task
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to create a task.');
        }

        $data->setOwner($user);

        $min = $this->tasks->findMinPositionForOwner($user);
        $data->setPosition(null === $min ? 0 : $min - 1);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
