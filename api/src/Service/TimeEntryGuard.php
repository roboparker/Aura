<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\TimeEntryRepository;
use App\Repository\TimesheetApprovalRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The write-time rules that guard tracked time, in one place.
 *
 * These are financial invariants — a billed entry backs an invoice line, and a
 * submitted week backs an approval — so REST and MCP must enforce them
 * identically. Extracted from {@see \App\State\TimeEntryUserProcessor} when the
 * MCP tools landed rather than copied, because a rule that drifts between the
 * two surfaces is a rule that silently isn't enforced on one of them.
 *
 * Rate, billability, duration and space are *not* here — the entity derives
 * those in its own PrePersist/PreUpdate hook, so every write path gets them
 * regardless of who calls.
 */
final class TimeEntryGuard
{
    public function __construct(
        private readonly TimeEntryRepository $timeEntries,
        private readonly TimesheetApprovalRepository $timesheets,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Stamp the tracker and settle the one-running-timer invariant.
     *
     * The tracker is set server-side, never from the payload, so a caller can't
     * log time as someone else. Starting a second running timer stops the first
     * — and the stop is flushed before the caller inserts, because the
     * "one running timer per user" partial unique index can't be deferred.
     */
    public function prepareCreate(TimeEntry $entry, User $user): void
    {
        $entry->setUser($user);

        if (null !== $entry->getEndedAt()) {
            return;
        }

        $running = $this->timeEntries->findRunningForUser($user);
        if (null !== $running && $running !== $entry) {
            $running->setEndedAt(new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    /**
     * Why an edit to this entry must be refused, or null when it may proceed.
     *
     * Returns a message rather than throwing so each surface can raise its own
     * error type — an HTTP 422 for REST, an `isError` result for MCP.
     */
    public function updateRefusalReason(TimeEntry $entry): ?string
    {
        // billedAt is never client-writable, so non-null means "already
        // invoiced". Editing it would desync the invoice from the time
        // behind it.
        if (null !== $entry->getBilledAt()) {
            return 'This entry is on an invoice and can no longer be edited.';
        }

        return null;
    }

    /**
     * Whether the entry falls in a week its tracker has already submitted for
     * approval. Applies to creates and edits alike — a locked week is closed.
     */
    public function weekIsLocked(TimeEntry $entry): bool
    {
        $space = $entry->getSpace() ?? $entry->getProject()?->getSpace();
        $tracker = $entry->getUser();
        $startedAt = $entry->getStartedAt();

        if (null === $space || null === $tracker || null === $startedAt) {
            return false;
        }

        return $this->timesheets->weekIsLocked($space, $tracker, $startedAt);
    }

    public const WEEK_LOCKED_MESSAGE = 'This week has been submitted for approval and is locked.';
}
