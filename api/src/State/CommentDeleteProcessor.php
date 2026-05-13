<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Comment;
use App\Service\CommentMercurePublisher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wraps the default ORM remove processor on `DELETE /comments/{id}`.
 * The publish has to happen *before* the SQL delete so the entity
 * still has its id and parent (task or page) — after `process()`
 * returns the in-memory object's id is gone and the Doctrine listener
 * has already detached it.
 *
 * @implements ProcessorInterface<Comment, void|Comment>
 */
final class CommentDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Comment, void|Comment> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private CommentMercurePublisher $publisher,
    ) {
    }

    /**
     * @param Comment $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // Snapshot first; the remove processor wipes the in-memory id.
        $this->publisher->publishDeleted($data);
        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
