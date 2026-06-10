<?php

namespace App\Scheduler;

use App\Message\DispatchNotificationDigest;
use App\Message\DispatchTaskReminders;
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
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->lock($this->lockFactory->createLock('scheduler-default'));
    }
}
