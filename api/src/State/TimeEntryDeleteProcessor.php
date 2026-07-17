<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TimeEntry;
use App\Repository\TimesheetSubmissionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Guards deletion of billed time entries. Once an entry has been pulled onto
 * an invoice ({@see TimeEntry::$billedAt}) it is part of a financial document —
 * deleting it would silently desync the invoice from the time that backs it.
 * The PWA hides the delete affordance for billed rows; this enforces the same
 * rule server-side (422, mirroring the edit guard in TimeEntryUserProcessor).
 *
 * Entries in a submitted (pending/approved) timesheet week (#654) are frozen
 * the same way — a rejection unlocks them.
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
        private TimesheetSubmissionRepository $timesheets,
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

        // Timesheet approvals (#654): a submitted week is frozen.
        $space = $data->getSpace();
        $tracker = $data->getUser();
        $startedAt = $data->getStartedAt();
        if (
            null !== $space && null !== $tracker && null !== $startedAt
            && $this->timesheets->weekIsLocked($space, $tracker, $startedAt)
        ) {
            throw new UnprocessableEntityHttpException(
                'This week has been submitted for approval and is locked.',
            );
        }

        $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
