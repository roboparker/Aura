<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PageComment;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Stamps the author on a new {@see PageComment} (#183). Same shape
 * as {@see CommentAuthorProcessor} for task comments — read-only on
 * the wire, set here once on POST.
 *
 * @implements ProcessorInterface<PageComment, PageComment>
 */
final class PageCommentAuthorProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<PageComment, PageComment> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    /**
     * @param PageComment $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PageComment
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to comment on a page.');
        }
        $data->setAuthor($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
