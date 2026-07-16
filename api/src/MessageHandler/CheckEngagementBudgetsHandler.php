<?php

namespace App\MessageHandler;

use App\Message\CheckEngagementBudgets;
use App\Service\EngagementBudgetAlerter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the engagement budget alert sweep (#651) when the scheduler fires
 * App\Message\CheckEngagementBudgets (nightly). Idempotent — each
 * engagement's budgetAlertsSent ledger stops duplicate emails.
 */
#[AsMessageHandler]
final class CheckEngagementBudgetsHandler
{
    public function __construct(private EngagementBudgetAlerter $alerter)
    {
    }

    public function __invoke(CheckEngagementBudgets $message): void
    {
        $this->alerter->dispatchDue();
    }
}
