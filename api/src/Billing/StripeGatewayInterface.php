<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Thin seam over the small slice of Stripe we use: create a hosted Checkout
 * Session, create a Billing Portal session, and verify + decode an incoming
 * webhook. Kept to domain types (strings + arrays) so the controller never
 * touches the transport, and so the test suite can swap in an in-memory fake
 * ({@see \App\Tests\Billing\InMemoryStripeGateway}) — mirroring the
 * PushSenderInterface pattern.
 *
 * The real implementation ({@see StripeGateway}) talks to the Stripe REST API
 * over Symfony HttpClient — no SDK dependency.
 */
interface StripeGatewayInterface
{
    /**
     * Whether a usable secret key is present. When false the gateway can't
     * make calls; the controller answers 503 so billing degrades cleanly on
     * an instance that hasn't been wired to Stripe yet.
     */
    public function isConfigured(): bool;

    /**
     * Create a subscription-mode Checkout Session and return its hosted URL.
     *
     * @param array<string, string> $metadata copied onto both the session and
     *                                         the resulting subscription so the
     *                                         webhook can resolve our space
     */
    public function createCheckoutSession(
        string $priceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        ?string $customerId,
        ?string $customerEmail,
        array $metadata,
    ): string;

    /**
     * Create a Billing Portal session for an existing customer and return its
     * hosted URL.
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): string;

    /**
     * Verify the `Stripe-Signature` header against the raw request body and
     * return the decoded event, or null when the signature is missing /
     * invalid / outside the timestamp tolerance (caller answers 400).
     *
     * @return array<string, mixed>|null
     */
    public function parseWebhookEvent(string $payload, string $signatureHeader): ?array;
}
