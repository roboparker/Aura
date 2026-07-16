<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Expense;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Blocks deleting an expense that already backs an invoice line (#650) —
 * same billed-lock as TimeEntryDeleteProcessor. Voiding the invoice releases
 * the expense first.
 *
 * @implements ProcessorInterface<Expense, void>
 */
final class ExpenseDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Expense, void> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
    ) {
    }

    /**
     * @param Expense $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (null !== $data->getBilledAt()) {
            throw new UnprocessableEntityHttpException(
                'This expense is on an invoice and can no longer be deleted.',
            );
        }

        $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
