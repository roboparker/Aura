<?php

namespace App\Message;

/**
 * Scheduler message: roll up pending notifications for users on the given
 * digest cadence into one grouped email each. Attached to the default
 * schedule by App\Scheduler\MainScheduleProvider (hourly at minute 55,
 * daily at 08:00 UTC).
 */
final class DispatchNotificationDigest
{
    public function __construct(private string $period)
    {
    }

    public function getPeriod(): string
    {
        return $this->period;
    }
}
