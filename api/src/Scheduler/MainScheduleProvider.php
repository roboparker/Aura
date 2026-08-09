<?php

namespace App\Scheduler;

use App\Message\CaptureUsageSnapshot;
use App\Message\CheckProjectBudgets;
use App\Message\DispatchNotificationDigest;
use App\Message\DispatchTaskReminders;
use App\Message\MarkOverdueInvoices;
use App\Message\SpawnRecurringInvoices;
use App\Message\PruneAccountExports;
use App\Message\PruneAutomationRuns;
use App\Message\PruneSpaceExports;
use App\Message\PullCalendarChanges;
use App\Message\PurgeDeletedOrganizations;
use App\Message\RunBackup;
use App\Message\SendGrowthDigest;
use App\Message\SendTimesheetNudges;
use App\Message\SweepDueTasks;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The app's recurring jobs, expressed as a symfony/scheduler schedule.
 *
 * `#[AsSchedule]` registers this under the schedule name `default`, which
 * materialises as a Messenger transport named `scheduler_default` — the
 * worker consumes it alongside `async` (`messenger:consume async
 * scheduler_default`), so no extra process or system cron is needed. Each
 * trigger fires the message through the normal Messenger pipeline, where
 * the matching App\MessageHandler handler runs it.
 *
 * All times are pinned to UTC so the cadence doesn't drift with the
 * container's locale. The handlers are idempotent, so an occasional
 * double-fire (e.g. two workers racing before the lock store is shared)
 * is at worst a wasted query, never a duplicate notification or email.
 *
 * - stateful(): remembers the last run across worker restarts (the worker
 *   recycles hourly via --time-limit), so a run that lands during the
 *   restart window is caught up instead of skipped — essential for the
 *   once-a-day digest.
 * - processOnlyLastMissedRun(): after longer downtime, collapse the
 *   backlog to a single catch-up run per message instead of replaying
 *   every missed tick.
 * - lock(): keeps concurrent workers from processing the same tick twice
 *   (with the default LOCK_DSN=flock this only covers workers on the same
 *   host — point LOCK_DSN at a shared store if the worker is ever scaled
 *   out, or keep consuming `scheduler_default` from a single replica).
 */
#[AsSchedule]
final class MainScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $utc = new \DateTimeZone('UTC');

        return (new Schedule())
            ->with(
                // Task reminders: every 5 minutes (the offsets are
                // minute-granular, so this bounds delivery lag at 5 min).
                RecurringMessage::cron('*/5 * * * *', new DispatchTaskReminders(), $utc),
                // Hourly digest at minute 55, so a digest covers (almost)
                // the full hour it's sent in.
                RecurringMessage::cron('55 * * * *', new DispatchNotificationDigest('hourly'), $utc),
                // Daily digest at 08:00 UTC — morning inbox for the
                // EU/US-overlap audience.
                RecurringMessage::cron('0 8 * * *', new DispatchNotificationDigest('daily'), $utc),
                // Nightly backup at 02:00 UTC (db dump + media archive +
                // newest-N retention prune — App\Service\BackupRunner).
                // Replaces the old scripts/backup.sh host cron; stateful()
                // means a run missed during downtime is caught up on the
                // next worker boot instead of silently skipped.
                RecurringMessage::cron('0 2 * * *', new RunBackup(), $utc),
                // Weekly growth digest: last week's signup -> paid funnel,
                // emailed to every admin (App\Service\GrowthDigestMailer).
                // Monday 09:00 UTC so the week's numbers land at the start of
                // the working week rather than over the weekend.
                RecurringMessage::cron('0 9 * * 1', new SendGrowthDigest(), $utc),
                // Board automations (#764): fire "due date passed" for tasks
                // that went overdue in the last few hours. Nothing raises this
                // from a request — a date passing is not a user action — so the
                // sweep is the only way the trigger ever fires.
                RecurringMessage::cron('5 * * * *', new SweepDueTasks(), $utc),
                // Automation run-log retention. 04:00 keeps it clear of the
                // backup and the export prunes.
                RecurringMessage::cron('0 4 * * *', new PruneAutomationRuns(), $utc),
                // Space-export retention: delete export zips + rows past
                // the app.space_export_retention_days window (default 7 —
                // App\Service\SpaceExportPruner). 03:30 keeps it clear of
                // the backup run.
                RecurringMessage::cron('30 3 * * *', new PruneSpaceExports(), $utc),
                // Account-export retention: same window as space exports
                // (app.account_export_retention_days, default 7 —
                // App\Service\AccountExportPruner). 03:45 keeps it clear of
                // the space-export prune.
                RecurringMessage::cron('45 3 * * *', new PruneAccountExports(), $utc),
                // Per-user usage rollup: snapshot yesterday's disk/db/call
                // usage + prune spent counter buckets (App\Service\
                // UsageSnapshotBuilder). 03:15 lands after midnight (so the
                // day it labels is complete) and clear of the other jobs.
                RecurringMessage::cron('15 3 * * *', new CaptureUsageSnapshot(), $utc),
                // Calendar pull: every 15 minutes, reflect provider-side moves
                // + deletions of Aura-managed events back onto their tasks
                // (App\Service\CalendarPuller, #582 Phase B). Bounds two-way
                // lag until push-notification channels land (Phase D).
                RecurringMessage::cron('*/15 * * * *', new PullCalendarChanges(), $utc),
                // Overdue invoices: flip sent invoices past their due date to
                // overdue, daily at 03:50 UTC (InvoiceRepository::markOverdue).
                // Project budget alerts (#651) — daily at 03:40 UTC
                // (App\Service\ProjectBudgetAlerter).
                RecurringMessage::cron('40 3 * * *', new CheckProjectBudgets(), $utc),
                RecurringMessage::cron('50 3 * * *', new MarkOverdueInvoices(), $utc),
                // Recurring invoices: clone due templates into fresh drafts,
                // daily at 03:55 UTC (App\Service\RecurringInvoiceSpawner).
                RecurringMessage::cron('55 3 * * *', new SpawnRecurringInvoices(), $utc),
                // Timesheet nudges (#668): Monday 09:00 UTC — members who
                // tracked time last week but haven't submitted it
                // (App\Service\TimesheetNudgeDispatcher).
                RecurringMessage::cron('0 9 * * 1', new SendTimesheetNudges(), $utc),
                // Organization purge (#billing Phase 1c): hard-delete orgs whose
                // 30-day deletion grace period has lapsed
                // (App\Service\OrganizationDeletionService). 04:20 keeps it
                // clear of the backup and the export prunes, and running it
                // daily means the delete lands within a day of the date the
                // owner was shown — never before it.
                RecurringMessage::cron('20 4 * * *', new PurgeDeletedOrganizations(), $utc),
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->lock($this->lockFactory->createLock('scheduler-default'));
    }
}
