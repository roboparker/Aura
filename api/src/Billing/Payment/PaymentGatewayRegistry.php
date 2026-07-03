<?php

declare(strict_types=1);

namespace App\Billing\Payment;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the active {@see PaymentGatewayInterface} by key (mirrors
 * {@see \App\Calendar\CalendarClientRegistry}). Stripe is the default; PayPal
 * slots in by implementing the interface + the `app.payment_gateway` tag.
 */
final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $byKey = [];

    /**
     * @param iterable<PaymentGatewayInterface> $gateways
     */
    public function __construct(
        #[AutowireIterator('app.payment_gateway')]
        iterable $gateways,
    ) {
        foreach ($gateways as $gateway) {
            $this->byKey[$gateway->key()] = $gateway;
        }
    }

    public function get(string $key): ?PaymentGatewayInterface
    {
        return $this->byKey[$key] ?? null;
    }

    /** The default provider (Stripe), or null if none is registered. */
    public function default(): ?PaymentGatewayInterface
    {
        return $this->byKey[StripePaymentGateway::KEY] ?? (0 === count($this->byKey) ? null : reset($this->byKey));
    }

    /**
     * Keys of the providers that are actually configured — what the PWA offers.
     *
     * @return list<string>
     */
    public function configuredKeys(): array
    {
        $keys = [];
        foreach ($this->byKey as $key => $gateway) {
            if ($gateway->isConfigured()) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
