<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Segment;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

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
        private Security $security,
    ) {
    }

    /**
     * @param Segment $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Segment
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to create a segment.');
        }

        $data->setCreatedBy($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
