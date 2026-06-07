<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * Single source of truth for the `/api/me`-shaped user payload.
 *
 * Three code paths return this shape and the PWA treats them as the same
 * User object: {@see \App\Controller\AuthController::login()} /
 * {@see \App\Controller\AuthController::me()} (password login + the bootstrap
 * fetch) and {@see \App\Security\TwoFactorJsonHandler::onAuthenticationSuccess()}
 * (the 2FA-challenge success path, which bypasses the controller because it is
 * wired into the firewall handler chain). Keeping the shape here stops the two
 * call sites from drifting out of sync.
 */
final class UserPayloadSerializer
{
    public function __construct(
        private TwoFactorRecoveryState $recoveryState,
        private SegmentEvaluator $segmentEvaluator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(User $user): array
    {
        // A waitlisted account holds no ROLE_USER and is boxed into the gate,
        // so it gets no segment roles. Everyone else has their active
        // ROLE_SEGMENT_* roles appended so the PWA can feature-gate UI with
        // the same role strings the backend's SegmentVoter answers for.
        $roles = $user->getRoles();
        if (!$user->isWaitlisted()) {
            $roles = array_values(array_unique([
                ...$roles,
                ...$this->segmentEvaluator->activeSegmentRolesFor($user),
            ]));
        }

        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $roles,
            'givenName' => $user->getGivenName(),
            'familyName' => $user->getFamilyName(),
            'nickname' => $user->getNickname(),
            'personalizedColor' => $user->getPersonalizedColor(),
            'avatarUrls' => $user->getAvatarUrls(),
            // Surfaced so the PWA can route a waitlisted account to the
            // /waitlist gate and keep it out of the rest of the app. The
            // account holds no ROLE_USER while this is true (see
            // User::getRoles), so the server-side block is authoritative —
            // this flag just drives the client UX.
            'waitlisted' => $user->isWaitlisted(),
            // Inlined so the PWA has notification + timezone settings on
            // initial render without an extra round-trip to /me/preferences.
            'preferences' => $user->getPreferences(),
            // Surfaced so the PWA can show "2FA on" in the security card
            // and the count-of-remaining-codes warning without an extra
            // round-trip to /me/2fa/status on every render.
            'twoFactor' => [
                'enabled' => $user->isTotpEnabled(),
                'method' => 'totp',
                'recoveryCodesRemaining' => $user->getRecoveryCodeCount(),
                'recoveryCodesTotal' => count($user->getRecoveryCodes()),
                'enabledAt' => $user->getTotpEnabledAt()?->format(\DateTimeInterface::ATOM),
                'lastVerifiedAt' => $user->getTotpLastVerifiedAt()?->format(\DateTimeInterface::ATOM),
                // Surfaced so the PWA can mount the recovery interstitial
                // immediately after a backup-code login — see
                // {@see \App\EventSubscriber\TwoFactorRecoveryListener}.
                'recoveryPending' => $this->recoveryState->isPending(),
            ],
        ];
    }
}
