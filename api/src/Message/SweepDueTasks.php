<?php

namespace App\Message;

/**
 * Look for tasks that have just become overdue and fire their rules (#764).
 *
 * The only automation event nothing raises from a request — no user action
 * happens when a date passes, so something has to go looking. Attached hourly
 * by App\Scheduler\MainScheduleProvider.
 */
final class SweepDueTasks
{
}
