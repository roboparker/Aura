<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Discussion;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Stamps the current user as the discussion author before persisting,
 * matching the trust model used by TaskCommentAuthorProcessor and
 * TaskOwnerProcessor — the field is set server-side rather than
 * trusted from the request payload.
 *
 * @implements ProcessorInterface<Discussion, Discussion>
 */
final class DiscussionAuthorProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Discussion, Discussion> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    /**
     * @param Discussion $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Discussion
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to start a discussion.');
        }

        $data->setAuthor($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
