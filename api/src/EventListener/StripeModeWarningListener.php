<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Billing\StripeGatewayInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Shouts when a **production** instance is wired to a Stripe *test* key
 * (#stripe-mode).
 *
 * That combination is a real hazard: everything looks like it works — Checkout
 * opens, webhooks fire, invoices flip to paid — but no money moves and no
 * customer is actually charged. The failure is silent by construction, so the
 * only defence is noise.
 *
 * Deliberately **not** a hard failure: booting a prod container against a
 * sandbox is a legitimate staging / rehearsal setup, and refusing to start
 * would turn a misconfiguration into an outage. Instead we log at `critical`,
 * which trips the prod fingers_crossed handler (→ stderr) and reaches Sentry
 * via the Logs handler, so it can't be missed.
 *
 * Fires once per PHP process (workers are long-lived, so per-request logging
 * would flood), on both the HTTP and console paths — a cron or worker container
 * is just as capable of being misconfigured as the web one.
 */
final class StripeModeWarningListener
{
    private const MESSAGE = 'Stripe is in TEST mode on a production instance: '
        . 'no real payments will be processed. Set a live secret key (sk_live_…) '
        . 'to take real money, or ignore this if the instance is intentionally '
        . 'running against a sandbox.';

    private bool $warned = false;

    public function __construct(
        private readonly StripeGatewayInterface $stripe,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->warn();
    }

    #[AsEventListener]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->warn();
    }

    private function warn(): void
    {
        if ($this->warned) {
            return;
        }
        $this->warned = true;

        // Only the prod/test-key pairing is alarming. An unconfigured instance
        // (no key at all) reports test mode too, but it can't charge anything
        // and already degrades to 503 — nothing to warn about.
        if ('prod' !== $this->environment || !$this->stripe->isConfigured() || !$this->stripe->isTestMode()) {
            return;
        }

        $this->logger->critical(self::MESSAGE, ['environment' => $this->environment, 'stripe_mode' => 'test']);
    }
}
