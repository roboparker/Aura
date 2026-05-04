<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\TwoFactorSetupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Self-service 2FA management endpoints. The actual challenge during
 * login is handled by SchebTwoFactorBundle's firewall listener at
 * /auth/2fa-check; everything here is for the *settings* surface
 * (enable, confirm, disable, rotate recovery codes).
 *
 * Disable + recovery-rotation both require the current password to be
 * re-entered — that protects users whose session is stolen from having
 * 2FA silently turned off without the underlying password.
 */
class TwoFactorController extends AbstractController
{
    public function __construct(
        private TwoFactorSetupService $setup,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private RateLimiterFactoryInterface $twoFactorVerifyLimiter,
    ) {
    }

    #[Route('/me/2fa/setup', name: 'me_2fa_setup', methods: ['POST'])]
    public function startSetup(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if ($user->isTotpEnabled()) {
            return $this->json(['error' => '2FA is already enabled.'], 409);
        }

        $secret = $this->setup->startSetup($user);
        $this->em->flush();

        return $this->json([
            'secret' => $secret,
            'provisioningUri' => $this->setup->buildProvisioningUri($user),
        ]);
    }

    #[Route('/me/2fa/verify', name: 'me_2fa_verify', methods: ['POST'])]
    public function verify(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if ($user->isTotpEnabled()) {
            return $this->json(['error' => '2FA is already enabled.'], 409);
        }
        if (null === $user->getDecryptedTotpSecret()) {
            return $this->json(['error' => 'Start setup before verifying a code.'], 409);
        }

        $limit = $this->twoFactorVerifyLimiter->create('user-' . $user->getId())->consume();
        if (!$limit->isAccepted()) {
            return $this->json(['error' => 'Too many attempts. Try again later.'], 429);
        }

        $code = (string) ($this->jsonBody($request)['code'] ?? '');
        if (!$this->setup->verifyCode($user, $code)) {
            return $this->json(['error' => 'Invalid code.'], 422);
        }

        $user->setTotpEnabled(true);
        $recoveryCodes = $this->setup->regenerateRecoveryCodes($user);
        $this->em->flush();

        return $this->json([
            'enabled' => true,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    #[Route('/me/2fa', name: 'me_2fa_disable', methods: ['DELETE'])]
    public function disable(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$user->isTotpEnabled()) {
            return $this->json(['error' => '2FA is not enabled.'], 409);
        }

        $body = $this->jsonBody($request);
        $password = (string) ($body['currentPassword'] ?? '');
        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Current password is incorrect.'], 400);
        }

        $this->setup->disable($user);
        $this->em->flush();

        return $this->json(['enabled' => false]);
    }

    #[Route('/me/2fa/recovery-codes', name: 'me_2fa_recovery_codes_regenerate', methods: ['POST'])]
    public function regenerateRecoveryCodes(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$user->isTotpEnabled()) {
            return $this->json(['error' => '2FA is not enabled.'], 409);
        }

        $body = $this->jsonBody($request);
        $password = (string) ($body['currentPassword'] ?? '');
        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Current password is incorrect.'], 400);
        }

        $codes = $this->setup->regenerateRecoveryCodes($user);
        $this->em->flush();

        return $this->json(['recoveryCodes' => $codes]);
    }

    #[Route('/me/2fa/status', name: 'me_2fa_status', methods: ['GET'])]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        return $this->json([
            'enabled' => $user->isTotpEnabled(),
            'recoveryCodesRemaining' => $user->getRecoveryCodeCount(),
            'enabledAt' => $user->getTotpEnabledAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
