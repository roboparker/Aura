<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Feedback;
use App\Repository\FeedbackRepository;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps a new feedback ticket with its owner (the current user) and a
 * monotonic ticket number pulled from the `feedback_ticket_seq`
 * sequence, then hands off to the default Doctrine persist processor.
 *
 * Both fields are set server-side and read-only on the wire — the same
 * trust model as PageAuthorProcessor (author) and
 * UserGroupOwnerProcessor (slug). Using a DB sequence for the number
 * keeps it concurrency-safe: two simultaneous POSTs get distinct values
 * without a MAX()+1 race or a unique-constraint retry loop.
 *
 * @implements ProcessorInterface<Feedback, Feedback>
 */
final class FeedbackOwnerProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Feedback, Feedback> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
        private FeedbackRepository $feedbackRepository,
    ) {
    }

    /**
     * @param Feedback $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Feedback
    {
        $user = $this->auth->requireUser('submit feedback');

        $data->setOwner($user);
        if (null === $data->getTicketNumber()) {
            $data->setTicketNumber($this->feedbackRepository->nextTicketNumber());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
