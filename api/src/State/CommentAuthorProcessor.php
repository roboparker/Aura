<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Comment;
use App\Entity\User;
use App\Service\CommentActivityNotifier;
use App\Service\CommentMentionService;
use App\Service\CommentMercurePublisher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Stamps the comment with the currently authenticated user before
 * persisting, then notifies Mercure subscribers and dispatches
 * notifications: `@mention`s first (so they win the per-comment
 * precedence), then `reply` / `comment` thread activity. Author is
 * never trusted from the request payload — same pattern as
 * TaskOwnerProcessor.
 *
 * @implements ProcessorInterface<Comment, Comment>
 */
final class CommentAuthorProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Comment, Comment> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
        private CommentMercurePublisher $publisher,
        private CommentMentionService $mentions,
        private CommentActivityNotifier $activity,
    ) {
    }

    /**
     * @param Comment $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Comment
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to comment.');
        }

        $data->setAuthor($user);

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        $this->mentions->dispatchMentions($result);
        $this->activity->dispatch($result);
        $this->publisher->publishCreated($result);

        return $result;
    }
}
