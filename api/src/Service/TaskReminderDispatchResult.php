<?php

namespace App\Service;

/**
 * Summary of one TaskReminderDispatcher pass, surfaced by the console
 * command's success line and the scheduler handler's log entry.
 */
final readonly class TaskReminderDispatchResult
{
    public function __construct(
        public int $created,
        public int $emailsSent,
        public int $pushesSent,
    ) {
    }
}
