<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Comment;
use App\Service\CommentMentionService;
use App\Service\CommentMercurePublisher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wraps the default ORM persist processor on `PATCH /comments/{id}` so we
 * can publish the edit to Mercure subscribers after the row has been
 * flushed. Same pattern as TaskUpdateProcessor.
 *
 * @implements ProcessorInterface<Comment, Comment>
 */
final class CommentUpdateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Comment, Comment> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private CommentMercurePublisher $publisher,
        private CommentMentionService $mentions,
    ) {
    }

    /**
     * @param Comment $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Comment
    {
        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        // Edits can introduce new @mentions; the (recipient, comment)
        // unique key + alreadyNotified short-circuit keep prior mentions
        // from re-firing.
        $this->mentions->dispatchMentions($result);
        $this->publisher->publishUpdated($result);
        return $result;
    }
}
