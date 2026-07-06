<?php

declare(strict_types=1);

namespace App\Billing\Payment;

use App\Billing\StripeGatewayInterface;
use App\Entity\Invoice;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Stripe implementation of {@see PaymentGatewayInterface} for one-off invoice
 * payment — delegates to the existing {@see StripeGatewayInterface} (HttpClient,
 * no SDK) so membership billing and invoice payment share one Stripe seam.
 */
#[AutoconfigureTag('app.payment_gateway')]
final class StripePaymentGateway implements PaymentGatewayInterface
{
    public const KEY = 'stripe';

    public function __construct(private readonly StripeGatewayInterface $stripe)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return $this->stripe->isConfigured();
    }

    public function createInvoicePayment(Invoice $invoice, string $successUrl, string $cancelUrl): string
    {
        $label = $invoice->getNumber();
        $description = 'Invoice ' . (null !== $label && '' !== $label ? $label : (string) $invoice->getId());

        return $this->stripe->createPaymentCheckout(
            $invoice->getTotal(),
            $invoice->getCurrency(),
            $description,
            $successUrl,
            $cancelUrl,
            $invoice->getClient()?->getEmail(),
            ['invoice_id' => (string) $invoice->getId()],
        );
    }
}
