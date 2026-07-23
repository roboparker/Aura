<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\StripeModeWarningListener;
use App\Tests\Billing\InMemoryStripeGateway;
use App\Tests\Support\CollectingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A prod instance wired to a Stripe *test* key looks like it works while
 * charging nobody — the one failure mode that can't announce itself. These
 * pin the listener that does the announcing (#stripe-mode).
 */
class StripeModeWarningListenerTest extends TestCase
{
    public function testWarnsOnceWhenProdRunsATestKey(): void
    {
        $logger = new CollectingLogger();
        $listener = new StripeModeWarningListener($this->gateway(testMode: true), $logger, 'prod');

        $listener->onKernelRequest($this->request());
        $listener->onKernelRequest($this->request());

        // Once per process, not once per request — workers are long-lived and
        // a per-request line would bury the signal it's meant to raise.
        $this->assertCount(1, $logger->records);
        $this->assertSame('critical', $logger->records[0]['level']);
        $this->assertStringContainsString('TEST mode', $logger->records[0]['message']);
    }

    public function testStaysQuietOnALiveKey(): void
    {
        $logger = new CollectingLogger();
        $listener = new StripeModeWarningListener($this->gateway(testMode: false), $logger, 'prod');

        $listener->onKernelRequest($this->request());

        $this->assertSame([], $logger->records);
    }

    public function testStaysQuietOutsideProd(): void
    {
        // Dev + the test suite run against a sandbox by design.
        $logger = new CollectingLogger();
        $listener = new StripeModeWarningListener($this->gateway(testMode: true), $logger, 'dev');

        $listener->onKernelRequest($this->request());

        $this->assertSame([], $logger->records);
    }

    public function testStaysQuietWhenStripeIsNotConfigured(): void
    {
        // No key at all can't charge anyone; billing already degrades to 503.
        $gateway = $this->gateway(testMode: true);
        $gateway->configured = false;
        $logger = new CollectingLogger();
        $listener = new StripeModeWarningListener($gateway, $logger, 'prod');

        $listener->onKernelRequest($this->request());

        $this->assertSame([], $logger->records);
    }

    public function testIgnoresSubRequests(): void
    {
        $logger = new CollectingLogger();
        $listener = new StripeModeWarningListener($this->gateway(testMode: true), $logger, 'prod');

        $listener->onKernelRequest($this->request(HttpKernelInterface::SUB_REQUEST));

        $this->assertSame([], $logger->records);
    }

    private function gateway(bool $testMode): InMemoryStripeGateway
    {
        $gateway = new InMemoryStripeGateway();
        $gateway->testMode = $testMode;

        return $gateway;
    }

    private function request(int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            $type,
        );
    }
}
