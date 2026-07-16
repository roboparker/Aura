<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TimeEntry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Guards deletion of billed time entries. Once an entry has been pulled onto
 * an invoice ({@see TimeEntry::$billedAt}) it is part of a financial document —
 * deleting it would silently desync the invoice from the time that backs it.
 * The PWA hides the delete affordance for billed rows; this enforces the same
 * rule server-side (422, mirroring the edit guard in TimeEntryUserProcessor).
 *
 * @implements ProcessorInterface<TimeEntry, void>
 */
final class TimeEntryDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TimeEntry, void> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
    ) {
    }

    /**
     * @param TimeEntry $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (null !== $data->getBilledAt()) {
            throw new UnprocessableEntityHttpException(
                'This entry is on an invoice and can no longer be deleted.',
            );
        }

        $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
