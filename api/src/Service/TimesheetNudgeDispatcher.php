<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Space;
use App\Entity\TimeEntry;
use App\Entity\TimesheetApproval;
use App\Entity\User;
use App\Repository\TimesheetApprovalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The weekly timesheet submission nudge (#668): every Monday, members who
 * tracked time last week but haven't submitted it for approval get one
 * reminder — in-app + email through NotificationDispatcher's usual
 * preference gates.
 *
 * Scope: only spaces where approvals are actually in use (any
 * TimesheetApproval row exists). A rejected week still nudges (the
 * member is expected to fix + re-submit); pending/approved doesn't.
 *
 * Idempotency: each nudge row stamps `reminderOffset` with
 * `timesheet-nudge:<weekStart>`, and the sweep skips recipients that
 * already have one — a re-run (or a missed-then-caught-up scheduler) can't
 * double-nudge a week.
 */
final class TimesheetNudgeDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimesheetApprovalRepository $approvals,
        private readonly NotificationDispatcher $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Returns how many nudges were sent. */
    public function dispatchDue(\DateTimeImmutable $now): int
    {
        $weekStart = TimesheetApprovalRepository::weekStartOf($now)->modify('-7 days');
        $weekEnd = $weekStart->modify('+7 days');
        $offsetKey = 'timesheet-nudge:' . $weekStart->format('Y-m-d');

        /** @var list<Space> $spaces */
        $spaces = $this->em->createQuery(
            'SELECT s FROM ' . Space::class . ' s
             WHERE EXISTS (SELECT 1 FROM ' . TimesheetApproval::class . ' t WHERE t.space = s)',
        )->getResult();

        $sent = 0;
        foreach ($spaces as $space) {
            /** @var list<User> $trackers */
            $trackers = $this->em->createQuery(
                'SELECT u FROM ' . User::class . ' u
                 WHERE EXISTS (
                     SELECT 1 FROM ' . TimeEntry::class . ' e
                     WHERE e.user = u AND e.space = :space AND e.endedAt IS NOT NULL
                       AND e.startedAt >= :from AND e.startedAt < :to
                 )',
            )
                ->setParameter('space', $space)
                ->setParameter('from', $weekStart)
                ->setParameter('to', $weekEnd)
                ->getResult();

            foreach ($trackers as $tracker) {
                $approval = $this->approvals->findForWeek($space, $tracker, $weekStart);
                if (null !== $approval && $approval->locksWeek()) {
                    continue; // pending or approved — nothing to nudge
                }
                if ($this->alreadyNudged($tracker, $offsetKey)) {
                    continue;
                }

                $notification = $this->notifier->notify(
                    recipient: $tracker,
                    type: Notification::TYPE_TIMESHEET_NUDGE,
                    actor: null,
                    title: sprintf(
                        "Don't forget to submit your timesheet for the week of %s",
                        $weekStart->format('M j'),
                    ),
                    body: null !== $approval
                        ? 'Your timesheet was rejected — fix the entries and submit the week again.'
                        : 'You tracked time last week but haven\'t submitted it for approval yet.',
                    targetPath: '/time',
                );
                if (null !== $notification) {
                    $notification->setReminderOffset($offsetKey);
                    ++$sent;
                }
            }
        }

        if ($sent > 0) {
            $this->em->flush();
            $this->logger->info(sprintf('Sent %d timesheet nudge(s).', $sent));
        }

        return $sent;
    }

    private function alreadyNudged(User $tracker, string $offsetKey): bool
    {
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(n.id) FROM ' . Notification::class . ' n
             WHERE n.recipient = :recipient AND n.type = :type AND n.reminderOffset = :offset',
        )
            ->setParameter('recipient', $tracker)
            ->setParameter('type', Notification::TYPE_TIMESHEET_NUDGE)
            ->setParameter('offset', $offsetKey)
            ->getSingleScalarResult();

        return $count > 0;
    }
}
