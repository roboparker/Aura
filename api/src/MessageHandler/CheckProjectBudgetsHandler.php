<?php

namespace App\MessageHandler;

use App\Message\CheckProjectBudgets;
use App\Service\ProjectBudgetAlerter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the project budget alert sweep (#651) when the scheduler fires
 * App\Message\CheckProjectBudgets (nightly). Idempotent — each
 * project's budgetAlertsSent ledger stops duplicate emails.
 */
#[AsMessageHandler]
final class CheckProjectBudgetsHandler
{
    public function __construct(private ProjectBudgetAlerter $alerter)
    {
    }

    public function __invoke(CheckProjectBudgets $message): void
    {
        $this->alerter->dispatchDue();
    }
}
