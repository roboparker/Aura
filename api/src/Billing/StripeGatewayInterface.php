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
     * Whether the configured key is a **test/sandbox** key (`sk_test_…`) rather
     * than live. The key *is* Stripe's mode switch, so this is derived from it
     * rather than a separate setting that could drift out of sync.
     *
     * Surfaced in the UI so nobody mistakes a sandbox payment for a real one —
     * an unconfigured instance reports true (nothing real can be charged).
     */
    public function isTestMode(): bool;

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
     * Create a one-off (mode=payment) Checkout Session for a single amount and
     * return its hosted URL — used for invoice payment, distinct from the
     * subscription checkout above. $metadata rides onto the session + payment
     * intent so the webhook can resolve our invoice.
     *
     * When $destinationAccountId is set the charge is created on the platform
     * but funds settle to that Stripe Connect account (a destination charge),
     * with an optional $applicationFeeAmount (minor units) kept by the platform.
     * Because the charge stays on the platform, the completion webhook fires on
     * the platform account like every other payment.
     *
     * @param int                   $amount   in minor units of $currency
     * @param array<string, string> $metadata
     */
    public function createPaymentCheckout(
        int $amount,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        ?string $customerEmail,
        array $metadata,
        ?string $destinationAccountId = null,
        ?int $applicationFeeAmount = null,
    ): string;

    /**
     * Create a Stripe Connect (Accounts v2, recipient-configured) account and
     * return its `acct_…` id. It receives destination-charge transfers; the
     * space owner completes onboarding through the hosted Account Link
     * ({@see createAccountLink}); Stripe collects their identity + payout
     * details, so we never touch bank data.
     */
    public function createConnectAccount(?string $email, ?string $country): string;

    /**
     * Create a single-use hosted onboarding (Account Link) URL for a connected
     * account. `$refreshUrl` is where Stripe sends the user if the link expires
     * before they finish; `$returnUrl` is where it sends them when they're done.
     */
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string;

    /**
     * Read a connected account's onboarding state.
     *
     * @return array{chargesEnabled: bool, detailsSubmitted: bool, payoutsEnabled: bool}
     */
    public function retrieveConnectAccount(string $accountId): array;

    /**
     * Create a Billing Portal session for an existing customer and return its
     * hosted URL.
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): string;

    /**
     * Schedule a subscription to cancel at the end of the current billing
     * period (Stripe `cancel_at_period_end=true`) — access continues until
     * then and no further invoice is charged. The authoritative state change
     * still arrives via the `customer.subscription.updated` webhook; this just
     * initiates it. Throws {@see BillingException} on a transport / API error.
     */
    public function cancelSubscriptionAtPeriodEnd(string $subscriptionId): void;

    /**
     * Cancel a subscription **immediately** (Stripe `DELETE /subscriptions/{id}`)
     * — used when an account is deleted, so the customer's card can't keep
     * being charged for an account that no longer exists. Unlike the
     * at-period-end variant this ends billing now. The mirror row's terminal
     * state still arrives via the `customer.subscription.deleted` webhook (a
     * no-op if the row has already cascaded away with the account). Throws
     * {@see BillingException} on a transport / API error.
     */
    public function cancelSubscription(string $subscriptionId): void;

    /**
     * Set the seat quantity on a per-seat subscription (#billing Phase 1c).
     * Stripe prices the change from the moment it lands, so this is what keeps
     * an org's bill honest as members join and leave.
     *
     * Stripe requires the target **subscription item** id, not the
     * subscription's — so the implementation reads the subscription first and
     * updates its sole item. Per-seat plans have exactly one item; a
     * multi-item subscription is not something we create, and is rejected
     * rather than guessed at.
     *
     * Proration is left to Stripe's default (`create_prorations`), so a seat
     * added mid-cycle is charged for the remainder of the period and a seat
     * removed earns a credit. Throws {@see BillingException} on a transport /
     * API error.
     */
    public function updateSubscriptionQuantity(string $subscriptionId, int $quantity): void;

    /**
     * Verify the `Stripe-Signature` header against the raw request body and
     * return the decoded event, or null when the signature is missing /
     * invalid / outside the timestamp tolerance (caller answers 400).
     *
     * @return array<string, mixed>|null
     */
    public function parseWebhookEvent(string $payload, string $signatureHeader): ?array;
}
