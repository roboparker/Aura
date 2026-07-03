<?php

namespace App\Message;

/**
 * Run a calendar-pull pass (#582 Phase B) — reflect provider-side
 * moves/deletions of Aura-managed events back onto their tasks.
 *
 * With no `connectionId` it's the scheduled sweep of every syncable connection
 * (every 15 min via App\Scheduler\MainScheduleProvider), which also ensures +
 * renews change-notification subscriptions. With a `connectionId` it's a
 * targeted pull for one connection, dispatched by the webhook controller when a
 * provider reports a change (#582 Phase D).
 */
final class PullCalendarChanges
{
    public function __construct(
        public readonly ?string $connectionId = null,
    ) {
    }
}
