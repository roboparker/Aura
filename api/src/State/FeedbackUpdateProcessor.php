<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Feedback;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Guards the admin-only `status` field on a feedback ticket edit.
 *
 * The Patch security expression already lets the owner OR an admin edit a
 * ticket (title / description / type), but moving a ticket along the
 * board — open → planned → in_progress → shipped / declined — is a
 * platform-admin action. Rather than maintaining a separate
 * denormalization group per role, this processor compares the incoming
 * status against the persisted one (`previous_data`) and 403s a non-admin
 * who tries to change it, then defers to the default persist processor.
 *
 * @implements ProcessorInterface<Feedback, Feedback>
 */
final class FeedbackUpdateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Feedback, Feedback> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    /**
     * @param Feedback $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Feedback
    {
        $previous = $context['previous_data'] ?? null;
        if (
            $previous instanceof Feedback
            && $previous->getStatus() !== $data->getStatus()
            && !$this->security->isGranted('ROLE_ADMIN')
        ) {
            throw new AccessDeniedHttpException('Only an administrator can change a ticket\'s status.');
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
