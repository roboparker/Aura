<?php

namespace App\MessageHandler;

use App\Message\SpawnRecurringInvoices;
use App\Service\RecurringInvoiceSpawner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Spawns due recurring invoices when the scheduler fires
 * App\Message\SpawnRecurringInvoices (daily).
 */
#[AsMessageHandler]
final class SpawnRecurringInvoicesHandler
{
    public function __construct(
        private RecurringInvoiceSpawner $spawner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SpawnRecurringInvoices $message): void
    {
        $count = $this->spawner->spawnDue(new \DateTimeImmutable('today'));

        if ($count > 0) {
            $this->logger->info(sprintf('Spawned %d recurring invoice(s).', $count));
        }
    }
}
