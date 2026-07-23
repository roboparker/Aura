<?php

declare(strict_types=1);

namespace App\Billing\Payment;

use App\Billing\StripeGatewayInterface;
use App\Entity\Invoice;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Stripe implementation of {@see PaymentGatewayInterface} for one-off invoice
 * payment — delegates to the existing {@see StripeGatewayInterface} (HttpClient,
 * no SDK) so membership billing and invoice payment share one Stripe seam.
 *
 * When the invoice's space has completed Stripe Connect onboarding the charge
 * is routed to that connected account (destination charge) so funds settle to
 * the space owner, with the platform keeping an optional application fee
 * (`app.stripe_connect_application_fee_bps`, basis points, default 0 = none).
 */
#[AutoconfigureTag('app.payment_gateway')]
final class StripePaymentGateway implements PaymentGatewayInterface
{
    public const KEY = 'stripe';

    private const BASIS_POINTS = 10000;

    public function __construct(
        private readonly StripeGatewayInterface $stripe,
        #[Autowire('%app.stripe_connect_application_fee_bps%')]
        private readonly int $applicationFeeBps = 0,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return $this->stripe->isConfigured();
    }

    public function isTestMode(): bool
    {
        return $this->stripe->isTestMode();
    }

    public function createInvoicePayment(Invoice $invoice, string $successUrl, string $cancelUrl): string
    {
        $label = $invoice->getNumber();
        $description = 'Invoice ' . (null !== $label && '' !== $label ? $label : (string) $invoice->getId());

        // Charge the balance due, not the full total — partial payments (#648)
        // may already cover part of the invoice.
        $amount = $invoice->getBalanceDue();

        // Route to the space's connected account when it can accept payments,
        // otherwise fall back to a plain platform charge.
        $space = $invoice->getSpace();
        $destination = null !== $space && $space->canAcceptClientPayments()
            ? $space->getStripeConnectAccountId()
            : null;
        $fee = null !== $destination && $this->applicationFeeBps > 0
            ? (int) round($amount * $this->applicationFeeBps / self::BASIS_POINTS)
            : null;

        return $this->stripe->createPaymentCheckout(
            $amount,
            $invoice->getCurrency(),
            $description,
            $successUrl,
            $cancelUrl,
            $invoice->getClient()?->getEmail(),
            ['invoice_id' => (string) $invoice->getId()],
            $destination,
            $fee,
        );
    }
}
