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
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private readonly string $secretKey,
        #[Autowire('%env(default::STRIPE_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->secretKey;
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
    ): string {
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
            'payment_intent_data' => ['metadata' => $metadata],
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
}
