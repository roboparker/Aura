<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * One source of truth for the "this session logged in with a backup code,
 * so it should be prompted to recover" flag.
 *
 * Lifecycle:
 *  - set by {@see \App\EventSubscriber\TwoFactorRecoveryListener} when a
 *    backup-code login succeeds;
 *  - read by {@see SensitiveActionVerifier} (allows password-based step-up
 *    even when TOTP is "on", since the user has just proven they don't
 *    have the authenticator) and by the user-payload serializers (so the
 *    PWA can mount the recovery interstitial);
 *  - cleared by {@see \App\Controller\TwoFactorController::disable()} and
 *    {@see \App\Controller\TwoFactorController::verify()} once the user
 *    has either turned 2FA off or re-enrolled a new authenticator.
 *
 * Stored as a session attribute (not on the user entity) — it's a property
 * of *this login*, not a persistent account state. Logout destroys it
 * automatically; a parallel session on another device is unaffected.
 */
final class TwoFactorRecoveryState
{
    private const SESSION_KEY = '_2fa_recovery_pending';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function isPending(): bool
    {
        $session = $this->session();
        return null !== $session && true === $session->get(self::SESSION_KEY, false);
    }

    public function markPending(): void
    {
        $this->session()?->set(self::SESSION_KEY, true);
    }

    public function clear(): void
    {
        $this->session()?->remove(self::SESSION_KEY);
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->hasSession()) {
            return null;
        }
        return $request->getSession();
    }
}
