<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Client;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the current user as the client's creator on create (preserved on
 * update), server-side rather than from the payload — matching
 * PageAuthorProcessor's trust model.
 *
 * @implements ProcessorInterface<Client, Client>
 */
final class ClientCreatorProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Client, Client> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
    ) {
    }

    /**
     * @param Client $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Client
    {
        $user = $this->auth->requireUser('manage clients');

        if (null === $data->getCreatedBy()) {
            $data->setCreatedBy($user);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
