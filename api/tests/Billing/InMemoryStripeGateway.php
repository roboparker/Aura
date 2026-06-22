<?php

namespace App\Tests\Billing;

use App\Billing\StripeGatewayInterface;

/**
 * Recording test double for {@see StripeGatewayInterface}. Captures every
 * Checkout / Portal request so tests can assert what would have been sent,
 * returns deterministic fake hosted URLs, and exposes the raw event body to
 * `parseWebhookEvent()` directly — billing tests POST a JSON body to the
 * webhook with a fixed signature header and this fake accepts it (signature
 * verification itself is unit-tested against the real gateway).
 */
final class InMemoryStripeGateway implements StripeGatewayInterface
{
    public const VALID_SIGNATURE = 'test-valid-signature';
    public const CHECKOUT_URL = 'https://checkout.stripe.test/session';
    public const PORTAL_URL = 'https://billing.stripe.test/portal';

    /** @var list<array{priceId: string, quantity: int, customerId: ?string, customerEmail: ?string, metadata: array<string, string>}> */
    public array $checkoutSessions = [];

    /** @var list<array{customerId: string, returnUrl: string}> */
    public array $portalSessions = [];

    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function createCheckoutSession(
        string $priceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        ?string $customerId,
        ?string $customerEmail,
        array $metadata,
    ): string {
        $this->checkoutSessions[] = [
            'priceId' => $priceId,
            'quantity' => $quantity,
            'customerId' => $customerId,
            'customerEmail' => $customerEmail,
            'metadata' => $metadata,
        ];

        return self::CHECKOUT_URL;
    }

    public function createBillingPortalSession(string $customerId, string $returnUrl): string
    {
        $this->portalSessions[] = ['customerId' => $customerId, 'returnUrl' => $returnUrl];

        return self::PORTAL_URL;
    }

    public function parseWebhookEvent(string $payload, string $signatureHeader): ?array
    {
        if (self::VALID_SIGNATURE !== $signatureHeader) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function reset(): void
    {
        $this->checkoutSessions = [];
        $this->portalSessions = [];
        $this->configured = true;
    }
}
