<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Emails the client a late-payment reminder for an overdue invoice (#649),
 * carrying a fresh public link (the plaintext token is minted by the caller —
 * only its sha256 lives at rest). Copy escalates with the reminder sequence
 * (1 = just went overdue, 2 = +7 days, 3 = final at +14 days).
 * No-ops when the client has no email on file.
 */
final class InvoiceReminderMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendReminder(Invoice $invoice, string $plainToken, int $sequence): void
    {
        $to = $invoice->getClient()?->getEmail();
        if (null === $to || '' === trim($to)) {
            return;
        }

        $from = $invoice->getSpace()?->getName() ?? 'Madori';
        $number = $invoice->getNumber() ?? 'invoice';
        $subject = 3 === $sequence
            ? sprintf('Final reminder: %s from %s is overdue', ucfirst($number), $from)
            : sprintf('Reminder: %s from %s is overdue', ucfirst($number), $from);

        $email = (new TemplatedEmail())
            ->to($to)
            ->subject($subject)
            ->htmlTemplate('emails/invoice_reminder.html.twig')
            ->textTemplate('emails/invoice_reminder.txt.twig')
            ->context([
                'invoice' => $invoice,
                'client' => $invoice->getClient(),
                'fromName' => $from,
                'sequence' => $sequence,
                'viewUrl' => $this->mail->frontendLink('/i/' . $plainToken),
            ]);

        $this->mail->send($email);
    }
}
