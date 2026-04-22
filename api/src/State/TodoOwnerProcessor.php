<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Todo;
use App\Entity\User;
use App\Repository\TodoRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Sets the owner of a new Todo to the currently authenticated user and
 * places it at the top of their list (one slot above the current minimum
 * position). Negative positions are intentional — they let new todos insert
 * at the top without rewriting every existing row.
 *
 * @implements ProcessorInterface<Todo, Todo>
 */
final class TodoOwnerProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Todo, Todo> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
        private TodoRepository $todos,
    ) {
    }

    /**
     * @param Todo $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Todo
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to create a todo.');
        }

        $data->setOwner($user);

        $min = $this->todos->findMinPositionForOwner($user);
        $data->setPosition(null === $min ? 0 : $min - 1);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
