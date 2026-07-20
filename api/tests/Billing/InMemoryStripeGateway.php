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
    public const PAYMENT_URL = 'https://checkout.stripe.test/pay';
    public const PORTAL_URL = 'https://billing.stripe.test/portal';
    public const ONBOARD_URL = 'https://connect.stripe.test/onboard';

    /** @var list<array{priceId: string, quantity: int, customerId: ?string, customerEmail: ?string, metadata: array<string, string>}> */
    public array $checkoutSessions = [];

    /** @var list<array{amount: int, currency: string, description: string, metadata: array<string, string>, destination: ?string, fee: ?int}> */
    public array $paymentSessions = [];

    /** @var list<array{customerId: string, returnUrl: string}> */
    public array $portalSessions = [];

    /** @var array<string, array{chargesEnabled: bool, detailsSubmitted: bool, payoutsEnabled: bool}> */
    public array $connectAccounts = [];

    /** @var list<array{account: string, refreshUrl: string, returnUrl: string}> */
    public array $accountLinks = [];

    private int $connectCounter = 0;

    /** @var list<string> Stripe subscription ids asked to cancel at period end. */
    public array $canceledSubscriptions = [];

    /** @var list<string> Stripe subscription ids asked to cancel immediately. */
    public array $immediatelyCanceledSubscriptions = [];

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
    ): string {
        $this->paymentSessions[] = [
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'metadata' => $metadata,
            'destination' => $destinationAccountId,
            'fee' => $applicationFeeAmount,
        ];

        return self::PAYMENT_URL;
    }

    public function createConnectAccount(?string $email, ?string $country): string
    {
        $id = 'acct_test_' . (++$this->connectCounter);
        // A fresh account starts un-onboarded; a test flips it ready via
        // {@see markConnectReady()} (or the account.updated webhook).
        $this->connectAccounts[$id] = [
            'chargesEnabled' => false,
            'detailsSubmitted' => false,
            'payoutsEnabled' => false,
        ];

        return $id;
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        $this->accountLinks[] = ['account' => $accountId, 'refreshUrl' => $refreshUrl, 'returnUrl' => $returnUrl];

        return self::ONBOARD_URL;
    }

    public function retrieveConnectAccount(string $accountId): array
    {
        return $this->connectAccounts[$accountId] ?? [
            'chargesEnabled' => false,
            'detailsSubmitted' => false,
            'payoutsEnabled' => false,
        ];
    }

    /** Test helper: mark a connected account fully onboarded. */
    public function markConnectReady(string $accountId): void
    {
        $this->connectAccounts[$accountId] = [
            'chargesEnabled' => true,
            'detailsSubmitted' => true,
            'payoutsEnabled' => true,
        ];
    }

    public function createBillingPortalSession(string $customerId, string $returnUrl): string
    {
        $this->portalSessions[] = ['customerId' => $customerId, 'returnUrl' => $returnUrl];

        return self::PORTAL_URL;
    }

    public function cancelSubscriptionAtPeriodEnd(string $subscriptionId): void
    {
        $this->canceledSubscriptions[] = $subscriptionId;
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->immediatelyCanceledSubscriptions[] = $subscriptionId;
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
        $this->paymentSessions = [];
        $this->portalSessions = [];
        $this->canceledSubscriptions = [];
        $this->immediatelyCanceledSubscriptions = [];
        $this->connectAccounts = [];
        $this->accountLinks = [];
        $this->connectCounter = 0;
        $this->configured = true;
    }
}
