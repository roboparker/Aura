<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PushSubscription;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the current user on a freshly-created PushSubscription before
 * persisting. Same trust model as TaskOwnerProcessor and
 * CommentAuthorProcessor — user is pulled from the security token, not
 * the request payload, so a client can't register a subscription on
 * someone else's behalf.
 *
 * @implements ProcessorInterface<PushSubscription, PushSubscription>
 */
final class PushSubscriptionOwnerProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<PushSubscription, PushSubscription> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
    ) {
    }

    /**
     * @param PushSubscription $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PushSubscription
    {
        $user = $this->auth->requireUser('register a push subscription');

        $data->setUser($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
