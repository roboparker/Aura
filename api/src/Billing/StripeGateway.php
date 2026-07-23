<?php

declare(strict_types=1);

namespace App\Billing;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stripe gateway backed by Symfony HttpClient against the Stripe REST API
 * (https://api.stripe.com/v1) — no SDK dependency. Our surface is tiny:
 * create a Checkout Session, create a Billing Portal session, and verify a
 * webhook signature.
 *
 * The secret + webhook signing keys come from env (blank on a fresh checkout,
 * like the VAPID keys); {@see isConfigured()} reports whether the instance is
 * wired to Stripe so callers can degrade cleanly. Webhook signature
 * verification follows Stripe's documented scheme: HMAC-SHA256 over
 * "{timestamp}.{raw body}" with the signing secret, compared in constant time,
 * inside a 5-minute timestamp tolerance.
 */
final class StripeGateway implements StripeGatewayInterface
{
    private const API_BASE = 'https://api.stripe.com/v1';
    private const API_BASE_V2 = 'https://api.stripe.com/v2';
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    /**
     * Stripe-Version pinned for the Accounts v2 calls (Connect). v2 endpoints
     * require an explicit version header; kept in sync with the account's
     * dashboard/webhook API version.
     */
    private const CONNECT_API_VERSION = '2026-06-24.dahlia';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        // `default::` resolves an unset *or empty* env var to null, which a
        // non-nullable string param rejects at instantiation — so a Stripe-less
        // instance used to fatal the moment anything touched the gateway
        // instead of degrading through isConfigured(). The `string:` cast
        // restores the '' this class is written against.
        #[Autowire('%env(string:default::STRIPE_SECRET_KEY)%')]
        private readonly string $secretKey,
        #[Autowire('%env(string:default::STRIPE_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->secretKey;
    }

    public function isTestMode(): bool
    {
        // Only an explicit live key counts as live: an unset or unrecognised
        // key can't charge anything real, so it reports test (fail-safe).
        return !str_starts_with($this->secretKey, 'sk_live_');
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
        $body = [
            'mode' => 'subscription',
            'line_items' => [
                ['price' => $priceId, 'quantity' => max(1, $quantity)],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'allow_promotion_codes' => 'true',
            // Stamp metadata on both the session and the subscription it
            // creates, so every later customer.subscription.* webhook carries
            // our space id without an extra retrieve call.
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
        ];
        if (null !== $customerId) {
            $body['customer'] = $customerId;
        } elseif (null !== $customerEmail) {
            $body['customer_email'] = $customerEmail;
        }

        $data = $this->request('POST', '/checkout/sessions', $body);
        $url = $data['url'] ?? null;
        if (!is_string($url) || '' === $url) {
            throw new BillingException('Stripe did not return a Checkout URL.');
        }

        return $url;
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
        $paymentIntentData = ['metadata' => $metadata];
        if (null !== $destinationAccountId) {
            // Destination charge: created on the platform, funds transferred to
            // the connected account. Keeps the completion webhook on the platform.
            $paymentIntentData['transfer_data'] = ['destination' => $destinationAccountId];
            if (null !== $applicationFeeAmount && $applicationFeeAmount > 0) {
                $paymentIntentData['application_fee_amount'] = min($applicationFeeAmount, max(0, $amount));
            }
        }

        $body = [
            'mode' => 'payment',
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => ['name' => $description],
                        'unit_amount' => max(0, $amount),
                    ],
                    'quantity' => 1,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            // Stamp our invoice id on both the session and the payment intent so
            // the checkout.session.completed webhook resolves it without a retrieve.
            'metadata' => $metadata,
            'payment_intent_data' => $paymentIntentData,
        ];
        if (null !== $customerEmail) {
            $body['customer_email'] = $customerEmail;
        }

        $data = $this->request('POST', '/checkout/sessions', $body);
        $url = $data['url'] ?? null;
        if (!is_string($url) || '' === $url) {
            throw new BillingException('Stripe did not return a Checkout URL.');
        }

        return $url;
    }

    public function createConnectAccount(?string $email, ?string $country): string
    {
        // Accounts v2 (POST /v2/core/accounts): a "recipient"-configured account
        // that receives destination-charge transfers into its Stripe balance and
        // onboards through the Stripe-hosted flow. The legacy v1 `type: express`
        // create is deprecated and rejected for new Connect platforms. See
        // https://docs.stripe.com/connect/accounts-v2.
        $body = [
            'dashboard' => 'express',
            'configuration' => [
                'recipient' => [
                    'capabilities' => [
                        'stripe_balance' => [
                            'stripe_transfers' => ['requested' => true],
                        ],
                    ],
                ],
            ],
            // For a recipient-only account the platform (application) is the
            // merchant of record on destination charges, so it collects fees +
            // covers losses — Stripe rejects any other value for this config.
            'defaults' => [
                'responsibilities' => [
                    'fees_collector' => 'application',
                    'losses_collector' => 'application',
                ],
            ],
            'include' => ['configuration.recipient'],
        ];
        if (null !== $email && '' !== $email) {
            $body['contact_email'] = $email;
        }
        // v2 requires identity.country before the recipient config can be set
        // (v1 collected it during onboarding). Default to US when the caller
        // doesn't supply one; the hosted flow still collects the rest.
        $countryCode = null !== $country && '' !== $country ? $country : 'US';
        $body['identity'] = ['country' => strtolower($countryCode)];

        $data = $this->requestV2('POST', '/core/accounts', $body);
        $id = $data['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new BillingException('Stripe did not return a connected account id.');
        }

        return $id;
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        // The v1 Account Links resource is the documented hosted-onboarding tool
        // for v2 accounts too — the `account` value just comes from a v2 create.
        $data = $this->request('POST', '/account_links', [
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);
        $url = $data['url'] ?? null;
        if (!is_string($url) || '' === $url) {
            throw new BillingException('Stripe did not return an onboarding URL.');
        }

        return $url;
    }

    public function retrieveConnectAccount(string $accountId): array
    {
        // Accounts v2 exposes readiness through the recipient configuration's
        // stripe_transfers capability rather than v1's charges_enabled flags.
        $data = $this->requestV2(
            'GET',
            '/core/accounts/' . rawurlencode($accountId) . '?include=configuration.recipient',
            [],
        );

        // Safely dig configuration.recipient.capabilities.stripe_balance.stripe_transfers.status
        $config = $data['configuration'] ?? null;
        $recipient = is_array($config) ? ($config['recipient'] ?? null) : null;
        $capabilities = is_array($recipient) ? ($recipient['capabilities'] ?? null) : null;
        $balance = is_array($capabilities) ? ($capabilities['stripe_balance'] ?? null) : null;
        $transfers = is_array($balance) ? ($balance['stripe_transfers'] ?? null) : null;
        $status = is_array($transfers) && isset($transfers['status']) && is_string($transfers['status'])
            ? $transfers['status']
            : '';

        // `active` = can receive transfers (settle destination charges); `pending`
        // = submitted, under review. Anything else = not yet usable.
        return [
            'chargesEnabled' => 'active' === $status,
            'detailsSubmitted' => in_array($status, ['active', 'pending'], true),
            'payoutsEnabled' => 'active' === $status,
        ];
    }

    public function createBillingPortalSession(string $customerId, string $returnUrl): string
    {
        $data = $this->request('POST', '/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);
        $url = $data['url'] ?? null;
        if (!is_string($url) || '' === $url) {
            throw new BillingException('Stripe did not return a Billing Portal URL.');
        }

        return $url;
    }

    public function cancelSubscriptionAtPeriodEnd(string $subscriptionId): void
    {
        // Updating the subscription (rather than DELETE) keeps it live until
        // the period end; Stripe flips it to canceled then and fires the
        // webhook we mirror from.
        $this->request('POST', '/subscriptions/' . rawurlencode($subscriptionId), [
            'cancel_at_period_end' => 'true',
        ]);
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        // Immediate cancel: DELETE ends the subscription now (vs the POST
        // cancel_at_period_end above which lets it ride out the period).
        $this->request('DELETE', '/subscriptions/' . rawurlencode($subscriptionId), []);
    }

    public function parseWebhookEvent(string $payload, string $signatureHeader): ?array
    {
        if ('' === $this->webhookSecret) {
            $this->logger->warning('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not set.');
            return null;
        }

        $timestamp = null;
        $candidateSignatures = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            $pair = explode('=', trim($segment), 2);
            if (2 !== \count($pair)) {
                continue;
            }
            [$key, $value] = $pair;
            if ('t' === $key) {
                $timestamp = $value;
            } elseif ('v1' === $key) {
                $candidateSignatures[] = $value;
            }
        }

        if (null === $timestamp || !ctype_digit($timestamp) || [] === $candidateSignatures) {
            return null;
        }
        if (abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        $matched = false;
        foreach ($candidateSignatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
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

    /**
     * Issue a form-encoded request to the Stripe API and return the decoded
     * JSON. Nested arrays in $body are flattened to Stripe's bracket notation
     * by HttpClient. Throws {@see BillingException} on transport errors or any
     * 4xx/5xx (surfacing Stripe's error message when present).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body): array
    {
        if (!$this->isConfigured()) {
            throw new BillingException('Stripe is not configured.');
        }

        try {
            $response = $this->httpClient->request($method, self::API_BASE . $path, [
                'auth_bearer' => $this->secretKey,
                'body' => $body,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new BillingException('Stripe request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            $message = 'HTTP ' . $status;
            $error = $data['error'] ?? null;
            if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                $message = $error['message'];
            }
            throw new BillingException('Stripe error: ' . $message);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Like {@see request()} but for the Accounts v2 surface: JSON request bodies
     * (not form-encoded) and the pinned `Stripe-Version` header. GET carries no
     * body; query params (e.g. `?include=…`) ride on `$path`.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function requestV2(string $method, string $path, array $body): array
    {
        if (!$this->isConfigured()) {
            throw new BillingException('Stripe is not configured.');
        }

        try {
            $options = [
                'auth_bearer' => $this->secretKey,
                'headers' => ['Stripe-Version' => self::CONNECT_API_VERSION],
            ];
            if ('GET' !== $method) {
                $options['json'] = $body;
            }
            $response = $this->httpClient->request($method, self::API_BASE_V2 . $path, $options);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new BillingException('Stripe request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            $message = 'HTTP ' . $status;
            $error = $data['error'] ?? null;
            if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                $message = $error['message'];
            }
            // v2 errors may put the message at the top level instead of under `error`.
            if ('HTTP ' . $status === $message && isset($data['message']) && is_string($data['message'])) {
                $message = $data['message'];
            }
            throw new BillingException('Stripe error: ' . $message);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
