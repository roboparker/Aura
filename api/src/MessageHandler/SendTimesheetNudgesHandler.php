<?php

namespace App\MessageHandler;

use App\Message\SendTimesheetNudges;
use App\Service\TimesheetNudgeDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the weekly timesheet submission nudge sweep (#668) when the
 * scheduler fires App\Message\SendTimesheetNudges (Monday mornings).
 */
#[AsMessageHandler]
final class SendTimesheetNudgesHandler
{
    public function __construct(
        private TimesheetNudgeDispatcher $nudges,
    ) {
    }

    public function __invoke(SendTimesheetNudges $message): void
    {
        $this->nudges->dispatchDue(new \DateTimeImmutable());
    }
}
