<?php

namespace App\Service;

use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Emails a payment receipt after a successful subscription charge, driven by
 * Stripe's `invoice.payment_succeeded` webhook (see
 * {@see \App\Controller\BillingController}). Purely a confirmation — the
 * authoritative invoice + PDF live in Stripe, reachable via the linked hosted
 * invoice page and the in-app Billing portal.
 *
 * Takes primitives (not entities) so the webhook can feed it straight from the
 * decoded Stripe object without a lookup when `customer_email` is present.
 * Sending is best-effort at the call site — a dead transport must not fail the
 * webhook and trigger Stripe retries.
 */
final class SubscriptionReceiptMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendReceipt(
        string $toEmail,
        int $amountMinorUnits,
        string $currency,
        ?string $invoiceNumber,
        ?string $invoiceUrl,
    ): void {
        $email = (new TemplatedEmail())
            ->to($toEmail)
            ->subject('Your Madori payment receipt')
            ->htmlTemplate('emails/subscription_receipt.html.twig')
            ->textTemplate('emails/subscription_receipt.txt.twig')
            ->context([
                'amount' => $amountMinorUnits,
                'currency' => strtoupper($currency),
                'invoiceNumber' => $invoiceNumber,
                'invoiceUrl' => $invoiceUrl,
            ]);

        $this->mail->send($email);
    }
}
