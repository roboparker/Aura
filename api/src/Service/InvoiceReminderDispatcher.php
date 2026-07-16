<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The late-payment reminder sweep (#649), run daily right after the overdue
 * flip: for every overdue invoice with reminders enabled, sends the next
 * reminder in the schedule (on overdue, +7d, +14d after the due date) if its
 * day has arrived. The {@see Invoice::$remindersSentAt} log is the idempotency
 * ledger — a re-run on the same day finds the reminder already logged and
 * sends nothing, and a missed day self-heals on the next run (at most one
 * reminder per invoice per run).
 *
 * Each reminder rotates the invoice's public token (the plaintext is
 * unrecoverable by design, sha256 at rest) so the freshest email always
 * carries a working link — same pattern as invite resends.
 */
final class InvoiceReminderDispatcher
{
    /** Days after the due date each reminder fires (index = reminders already sent). */
    private const OFFSETS_DAYS = [0, 7, 14];

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceReminderMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Returns how many reminders were sent. */
    public function dispatchDue(\DateTimeImmutable $today): int
    {
        $sent = 0;
        foreach ($this->invoices->findOverdueForReminders() as $invoice) {
            if ($this->sendNextIfDue($invoice, $today)) {
                ++$sent;
            }
        }
        if ($sent > 0) {
            $this->em->flush();
            $this->logger->info(sprintf('Sent %d late-payment reminder(s).', $sent));
        }

        return $sent;
    }

    private function sendNextIfDue(Invoice $invoice, \DateTimeImmutable $today): bool
    {
        $dueDate = $invoice->getDueDate();
        $email = $invoice->getClient()?->getEmail();
        if (null === $dueDate || null === $email || '' === trim($email)) {
            return false;
        }

        $alreadySent = count($invoice->getRemindersSentAt());
        if ($alreadySent >= count(self::OFFSETS_DAYS)) {
            return false;
        }
        $fireDate = $dueDate->modify(sprintf('+%d days', self::OFFSETS_DAYS[$alreadySent]));
        if ($today < $fireDate) {
            return false;
        }

        // Rotate the public token so the reminder link always works.
        $plain = bin2hex(random_bytes(32));
        $invoice->setPublicToken(hash('sha256', $plain));
        $invoice->addReminderSentAt(new \DateTimeImmutable());

        $this->mailer->sendReminder($invoice, $plain, $alreadySent + 1);

        return true;
    }
}
