<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Emails a client the "here's your invoice" link when it's sent (#445). The
 * link carries the plaintext public token (only its sha256 is stored) and
 * points at the PWA's public /i/{token} page, where the client can view,
 * download the PDF, and pay. No-ops when the client has no email on file.
 */
final class InvoiceMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendInvoice(Invoice $invoice, string $plainToken): void
    {
        $to = $invoice->getClient()?->getEmail();
        if (null === $to || '' === trim($to)) {
            return;
        }

        $from = $invoice->getSpace()?->getName() ?? 'Madori';
        $number = $invoice->getNumber() ?? 'invoice';

        $email = (new TemplatedEmail())
            ->to($to)
            ->subject(sprintf('%s from %s', ucfirst($number), $from))
            ->htmlTemplate('emails/invoice.html.twig')
            ->textTemplate('emails/invoice.txt.twig')
            ->context([
                'invoice' => $invoice,
                'client' => $invoice->getClient(),
                'fromName' => $from,
                'viewUrl' => $this->mail->frontendLink('/i/' . $plainToken),
            ]);

        $this->mail->send($email);
    }
}
