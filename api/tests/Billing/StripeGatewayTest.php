<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\StripeGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Unit coverage for the security-critical bit of {@see StripeGateway}: webhook
 * signature verification. The HTTP client is never touched here (a
 * MockHttpClient stands in), so these run offline.
 */
class StripeGatewayTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    public function testValidSignatureParsesEvent(): void
    {
        $payload = '{"type":"customer.subscription.created","data":{"object":{"id":"sub_1"}}}';
        $header = $this->signature($payload, self::SECRET, time());

        $event = $this->gateway()->parseWebhookEvent($payload, $header);

        $this->assertIsArray($event);
        $this->assertSame('customer.subscription.created', $event['type'] ?? null);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $header = $this->signature('{"amount":1}', self::SECRET, time());

        // Body differs from what was signed → signature mismatch → null.
        $this->assertNull($this->gateway()->parseWebhookEvent('{"amount":1000000}', $header));
    }

    public function testWrongSecretIsRejected(): void
    {
        $payload = '{"type":"ping"}';
        $header = $this->signature($payload, 'whsec_attacker', time());

        $this->assertNull($this->gateway()->parseWebhookEvent($payload, $header));
    }

    public function testExpiredTimestampIsRejected(): void
    {
        $payload = '{"type":"ping"}';
        $header = $this->signature($payload, self::SECRET, time() - 1000);

        $this->assertNull($this->gateway()->parseWebhookEvent($payload, $header));
    }

    public function testMalformedHeaderIsRejected(): void
    {
        $this->assertNull($this->gateway()->parseWebhookEvent('{"type":"ping"}', 'garbage'));
    }

    public function testMissingWebhookSecretRejectsEverything(): void
    {
        $payload = '{"type":"ping"}';
        $header = $this->signature($payload, self::SECRET, time());

        $gateway = new StripeGateway(new MockHttpClient(), 'sk_test', '');
        $this->assertNull($gateway->parseWebhookEvent($payload, $header));
    }

    public function testIsConfiguredReflectsSecretKey(): void
    {
        $this->assertTrue((new StripeGateway(new MockHttpClient(), 'sk_test', self::SECRET))->isConfigured());
        $this->assertFalse((new StripeGateway(new MockHttpClient(), '', self::SECRET))->isConfigured());
    }

    private function gateway(): StripeGateway
    {
        return new StripeGateway(new MockHttpClient(), 'sk_test', self::SECRET);
    }

    private function signature(string $payload, string $secret, int $timestamp): string
    {
        $hmac = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return 't=' . $timestamp . ',v1=' . $hmac;
    }
}
