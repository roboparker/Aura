<?php

namespace App\MessageHandler;

use App\Message\PruneAutomationRuns;
use App\Repository\AutomationRunRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Prunes the automation run log to the retention window.
 *
 * Mirrors the export pruners: a single set-based delete, logged, and safe to
 * run repeatedly.
 */
#[AsMessageHandler]
final class PruneAutomationRunsHandler
{
    public function __construct(
        private AutomationRunRepository $runs,
        private LoggerInterface $logger,
        #[Autowire('%app.automation_run_retention_days%')]
        private int $retentionDays,
    ) {
    }

    public function __invoke(PruneAutomationRuns $message): void
    {
        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d days', $this->retentionDays));
        $deleted = $this->runs->deleteOlderThan($cutoff);

        if ($deleted > 0) {
            $this->logger->info('Pruned automation runs.', [
                'deleted' => $deleted,
                'olderThan' => $cutoff->format('Y-m-d'),
            ]);
        }
    }
}
