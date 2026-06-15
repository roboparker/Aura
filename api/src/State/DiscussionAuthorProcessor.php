<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Discussion;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the current user as the discussion author before persisting,
 * matching the trust model used by CommentAuthorProcessor and
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
        private AuthenticatedUserResolver $auth,
    ) {
    }

    /**
     * @param Discussion $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Discussion
    {
        $user = $this->auth->requireUser('start a discussion');

        $data->setAuthor($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
