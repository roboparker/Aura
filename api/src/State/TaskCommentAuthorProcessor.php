<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TaskComment;
use App\Entity\User;
use App\Service\TaskCommentMentionService;
use App\Service\TaskCommentMercurePublisher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Stamps the comment with the currently authenticated user before persisting,
 * then notifies Mercure subscribers of the new comment so collaborators see
 * it without reloading. Author is never trusted from the request payload —
 * same pattern as TaskOwnerProcessor.
 *
 * @implements ProcessorInterface<TaskComment, TaskComment>
 */
final class TaskCommentAuthorProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TaskComment, TaskComment> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
        private TaskCommentMercurePublisher $publisher,
        private TaskCommentMentionService $mentions,
    ) {
    }

    /**
     * @param TaskComment $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TaskComment
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to comment.');
        }

        $data->setAuthor($user);

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        $this->mentions->dispatchMentions($result);
        $this->publisher->publishCreated($result);

        return $result;
    }
}
