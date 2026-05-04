<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ApiToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Plain delete pass-through. Exists so the operation has an explicit
 * processor reference for future hooks (audit trail, revocation events
 * pushed to MCP clients) without having to swap the wiring later.
 *
 * @implements ProcessorInterface<ApiToken, void>
 */
final class ApiTokenDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<ApiToken, void> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
    ) {
    }

    /**
     * @param ApiToken $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
