<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Segment;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the creating admin onto a new Segment, then hands off to the
 * default Doctrine persist processor.
 *
 * @implements ProcessorInterface<Segment, Segment>
 */
final class SegmentCreateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Segment, Segment> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
    ) {
    }

    /**
     * @param Segment $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Segment
    {
        $user = $this->auth->requireUser('create a segment');

        $data->setCreatedBy($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
