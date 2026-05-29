<?php

namespace App\Controller;

use App\Service\TwoFactorRecoveryState;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

class AuthController extends AbstractController
{
    public function __construct(
        private Security $security,
        private TwoFactorRecoveryState $recoveryState,
    ) {
    }

    #[Route('/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(#[CurrentUser] ?User $user): JsonResponse
    {
        // After json_login succeeds Scheb's AuthenticationTokenListener
        // wraps the token in a TwoFactorToken when the user has 2FA on.
        // The json_login firewall's default success path still routes
        // here, so we surface the "challenge needed" response ourselves
        // instead of leaking the user payload pre-2FA.
        $token = $this->security->getToken();
        if ($token instanceof TwoFactorTokenInterface) {
            return $this->json([
                'requiresTwoFactor' => true,
                'providers' => $token->getTwoFactorProviders(),
            ], 401);
        }

        if (null === $user) {
            return $this->json([
                'error' => 'Invalid credentials.',
            ], 401);
        }

        return $this->json($this->serializeUser($user));
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        return $this->json($this->serializeUser($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'givenName' => $user->getGivenName(),
            'familyName' => $user->getFamilyName(),
            'nickname' => $user->getNickname(),
            'personalizedColor' => $user->getPersonalizedColor(),
            'avatarUrls' => $user->getAvatarUrls(),
            // Inlined so the PWA can apply the saved theme on initial render
            // without an extra round-trip to /me/preferences.
            'preferences' => $user->getPreferences(),
            // Surfaced so the PWA can show "2FA on" in the security card
            // and the count-of-remaining-codes warning without an extra
            // round-trip to /me/2fa/status on every render.
            'twoFactor' => [
                'enabled' => $user->isTotpEnabled(),
                'recoveryCodesRemaining' => $user->getRecoveryCodeCount(),
                // Surfaced so the PWA can mount the recovery interstitial
                // immediately after a backup-code login — see
                // {@see \App\EventSubscriber\TwoFactorRecoveryListener}.
                'recoveryPending' => $this->recoveryState->isPending(),
            ],
        ];
    }
}
