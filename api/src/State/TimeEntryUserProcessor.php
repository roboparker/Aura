<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TimeEntry;
use App\Repository\TimeEntryRepository;
use App\Repository\TimesheetApprovalRepository;
use App\Security\AuthenticatedUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Stamps the current user as the time entry's tracker on create, mirroring the
 * trust model of TaskOwnerProcessor — the owner is
 * set server-side, never from the payload. On update the existing owner is
 * preserved (the processor also runs on Patch), so a member can't reassign a
 * time entry to someone else.
 *
 * Starting a new running timer (a fresh entry with no endedAt) auto-stops the
 * user's previous running timer first, so the "one running timer per user"
 * invariant (a partial unique index) is upheld gracefully instead of surfacing
 * a constraint violation. The stop is flushed before the insert so the two
 * running rows never coexist (a partial unique index can't be deferred).
 *
 * @implements ProcessorInterface<TimeEntry, TimeEntry>
 */
final class TimeEntryUserProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TimeEntry, TimeEntry> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
        private TimeEntryRepository $timeEntries,
        private TimesheetApprovalRepository $timesheets,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param TimeEntry $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TimeEntry
    {
        $user = $this->auth->requireUser('track time');

        $isCreate = null === $data->getUser();

        // Billed entries are frozen: they back an invoice line, so editing them
        // would desync the financial document from the time behind it. billedAt
        // is never client-writable, so non-null here means "already invoiced".
        if (!$isCreate && null !== $data->getBilledAt()) {
            throw new UnprocessableEntityHttpException(
                'This entry is on an invoice and can no longer be edited.',
            );
        }

        if ($isCreate) {
            $data->setUser($user);

            // Starting a new running timer stops the previous one first.
            if (null === $data->getEndedAt()) {
                $running = $this->timeEntries->findRunningForUser($user);
                if (null !== $running && $running !== $data) {
                    $running->setEndedAt(new \DateTimeImmutable());
                    $this->em->flush();
                }
            }
        }

        // Timesheet approvals (#654): a submitted (pending/approved) week is
        // frozen for the entry's tracker — creates and edits both bounce.
        $space = $data->getSpace() ?? $data->getProject()?->getSpace();
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

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
