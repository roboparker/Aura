<?php

namespace App\MessageHandler;

use App\Message\MarkOverdueInvoices;
use App\Repository\InvoiceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Marks past-due sent invoices overdue when the scheduler fires
 * App\Message\MarkOverdueInvoices (daily). Idempotent — a re-run just finds
 * fewer (or no) still-sent past-due invoices.
 */
#[AsMessageHandler]
final class MarkOverdueInvoicesHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(MarkOverdueInvoices $message): void
    {
        $today = new \DateTimeImmutable('today');
        $count = $this->invoices->markOverdue($today);

        if ($count > 0) {
            $this->logger->info(sprintf('Marked %d invoice(s) overdue.', $count));
        }
    }
}
