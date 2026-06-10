<?php

namespace App\Service;

/**
 * Summary of one NotificationDigestDispatcher pass, surfaced by the
 * console command's success line and the scheduler handler's log entry.
 */
final readonly class NotificationDigestResult
{
    public function __construct(
        public int $emailsSent,
        public int $rowsDigested,
    ) {
    }
}
