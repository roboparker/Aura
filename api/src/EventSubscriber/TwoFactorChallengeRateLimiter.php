<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\TooManyTwoFactorAttemptsException;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-user throttle on the 2FA challenge endpoint.
 *
 * SchebTwoFactorBundle's firewall owns `/auth/2fa-check` and runs at
 * `kernel.request` priority 8; its `setResponse()` call stops
 * propagation, so a plain `kernel.request` subscriber never sees the
 * challenge requests. Instead we hook into Scheb's own
 * `scheb_two_factor.authentication.attempt` event — dispatched from
 * inside the firewall before the code is checked — pull the
 * half-authenticated user, and consume from the shared
 * `two_factor_verify` limiter (5/min token bucket, same bucket
 * {@see \App\Controller\TwoFactorController::verify()} uses for the
 * setup-confirm path so a password-compromised attacker can't double
 * their budget across the two endpoints).
 *
 * On exhaustion we throw a {@see TooManyTwoFactorAttemptsException}.
 * Scheb's check-listener catches AuthenticationException, dispatches
 * the FAILURE event, and re-throws to the firewall's failure handler —
 * {@see \App\Security\TwoFactorJsonHandler::onAuthenticationFailure()}
 * inspects the type and emits 429 + `Retry-After`. Every attempt
 * counts toward the limit, regardless of whether the code happened to
 * be correct, so a 6th submission gets rejected even if the user
 * suddenly types a valid TOTP.
 */
final class TwoFactorChallengeRateLimiter implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.two_factor_verify')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::ATTEMPT => 'onAttempt',
        ];
    }

    public function onAttempt(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        // Defensive: Scheb only fires this event on a TwoFactorToken
        // carrying the underlying user, so missing/anonymous user means
        // something upstream is wrong. Let the firewall reject normally
        // — rate-limiting an already-broken request would consume the
        // budget for nothing.
        if (!$user instanceof User || null === $user->getId()) {
            return;
        }

        $limit = $this->limiter->create('user-' . $user->getId())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        throw new TooManyTwoFactorAttemptsException($retryAfterSeconds);
    }
}
