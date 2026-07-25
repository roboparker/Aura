<?php

declare(strict_types=1);

namespace App\Billing\Payment;

use App\Entity\Invoice;

/**
 * A pluggable online-payment provider for client invoices (#445). Mirrors the
 * multi-provider shape of the calendar sync ({@see \App\Calendar\CalendarClientInterface}
 * / registry): Stripe first, PayPal next, both behind this one seam so the
 * public pay page and the webhook stay provider-agnostic.
 *
 * The same providers back membership billing too — the Stripe implementation
 * delegates to the existing {@see \App\Billing\StripeGatewayInterface}.
 */
interface PaymentGatewayInterface
{
    /** Stable provider key, e.g. 'stripe' or 'paypal'. */
    public function key(): string;

    /** Whether the provider is wired up (keys present) and can take payments. */
    public function isConfigured(): bool;

    /**
     * Whether the provider is running against its **sandbox** rather than live
     * money. Surfaced on the public pay page so a client can never mistake a
     * test-card payment for a real one; an unconfigured provider reports true,
     * since nothing real can be charged through it.
     */
    public function isTestMode(): bool;

    /**
     * Start a payment for the invoice's outstanding total and return the hosted
     * URL the buyer is redirected to. The provider stamps enough metadata
     * (our invoice id) that its webhook can mark the invoice paid.
     */
    public function createInvoicePayment(Invoice $invoice, string $successUrl, string $cancelUrl): string;
}
