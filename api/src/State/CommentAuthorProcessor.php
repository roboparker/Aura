<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Comment;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Stamps the comment with the currently authenticated user before persisting.
 * Author is never trusted from the request payload — same pattern as
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

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
