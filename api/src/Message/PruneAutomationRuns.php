<?php

namespace App\Message;

/**
 * Delete automation run rows past the retention window (#764).
 *
 * The run log is a debugging aid, not an audit trail — every task edit on an
 * automated board writes one, so unbounded it would dwarf the tasks it
 * describes. Attached nightly by App\Scheduler\MainScheduleProvider.
 */
final class PruneAutomationRuns
{
}
