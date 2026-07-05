<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\BillingProject;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the creator on a new {@see BillingProject}, defaults its currency from
 * the client, and back-fills its space from the client when the payload omits it
 * — server-side, mirroring {@see ClientCreatorProcessor}.
 *
 * @implements ProcessorInterface<BillingProject, BillingProject>
 */
final class BillingProjectCreatorProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<BillingProject, BillingProject> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
    ) {
    }

    /**
     * @param BillingProject $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BillingProject
    {
        $user = $this->auth->requireUser('manage billing projects');

        if (null === $data->getCreatedBy()) {
            $data->setCreatedBy($user);
        }

        $client = $data->getClient();
        if (null !== $client) {
            if (null === $data->getSpace()) {
                $data->setSpace($client->getSpace());
            }
            if (null === $data->getCurrency()) {
                $data->setCurrency($client->getCurrency());
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
