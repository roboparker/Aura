<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\SensitiveActionVerifier;
use App\Service\TwoFactorRecoveryMailer;
use App\Service\TwoFactorRecoveryState;
use App\Service\TwoFactorSetupService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Self-service 2FA management endpoints. The actual challenge during
 * login is handled by SchebTwoFactorBundle's firewall listener at
 * /auth/2fa-check; everything here is for the *settings* surface
 * (enable, confirm, disable, rotate recovery codes).
 *
 * Disable, recovery rotation, and recovery reveal all run through
 * {@see SensitiveActionVerifier}: since reaching these endpoints requires
 * 2FA to already be on, the step-up is always a fresh TOTP code from the
 * authenticator app (rate-limited per user). That protects users whose
 * session is stolen from having 2FA silently turned off — a stolen cookie
 * doesn't grant access to the authenticator device.
 */
class TwoFactorController extends AbstractController
{
    public function __construct(
        private TwoFactorSetupService $setup,
        private EntityManagerInterface $em,
        private SensitiveActionVerifier $verifier,
        private RateLimiterFactoryInterface $twoFactorVerifyLimiter,
        private TwoFactorRecoveryState $recoveryState,
        private TwoFactorRecoveryMailer $recoveryMailer,
        #[Autowire(service: 'logger')]
        private LoggerInterface $logger,
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

    /**
     * Re-enrollment for users who lost their authenticator. Distinct from
     * setup because 2FA is currently *on* — same shape on the wire
     * (returns a fresh secret + provisioning URI), but step-up auth is
     * required and the call invalidates the existing TOTP secret + every
     * remaining recovery code atomically. The user follows up by POSTing
     * the new TOTP to {@see verify()}; if they abandon the flow,
     * `isTotpEnabled` stays false until they do — recovery flag is held
     * open so the next page load re-prompts.
     *
     * Auth: when the session has the recovery flag set (just logged in
     * with a backup code), the verifier accepts `currentPassword`;
     * otherwise demands a current TOTP from the still-working
     * authenticator. Either way password/code is single-factor floor —
     * a stolen cookie alone can't silently rotate the second factor.
     */
    #[Route('/me/2fa/reenroll', name: 'me_2fa_reenroll', methods: ['POST'])]
    public function reenroll(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$user->isTotpEnabled()) {
            return $this->json(['error' => '2FA is not enabled.'], 409);
        }

        $err = $this->verifier->verify($user, $this->jsonBody($request));
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
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

        $code = $this->stringField($this->jsonBody($request), 'code');
        if (!$this->setup->verifyCode($user, $code)) {
            return $this->json(['error' => 'Invalid code.'], 422);
        }

        $user->setTotpEnabled(true);
        $recoveryCodes = $this->setup->regenerateRecoveryCodes($user);

        // Re-enrollment (the recovery interstitial path) ends here: clear
        // the session flag so the PWA stops mounting the interstitial,
        // and notify the account holder out-of-band that the secret has
        // been rotated. Mail failures are swallowed so a flaky SMTP
        // doesn't block the user from re-securing their account.
        $wasRecovery = $this->recoveryState->isPending();
        if ($wasRecovery) {
            $this->recoveryState->clear();
        }

        $this->em->flush();

        if ($wasRecovery) {
            try {
                $this->recoveryMailer->sendReconfigured($user);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Failed to send 2FA reconfigured notification email.', [
                    'userId' => (string) $user->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

        $err = $this->verifier->verify($user, $this->jsonBody($request));
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }

        $this->setup->disable($user);

        $wasRecovery = $this->recoveryState->isPending();
        if ($wasRecovery) {
            $this->recoveryState->clear();
        }

        $this->em->flush();

        if ($wasRecovery) {
            try {
                $this->recoveryMailer->sendDisabled($user);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Failed to send 2FA disabled notification email.', [
                    'userId' => (string) $user->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

        $err = $this->verifier->verify($user, $this->jsonBody($request));
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }

        $codes = $this->setup->regenerateRecoveryCodes($user);
        $this->em->flush();

        return $this->json(['recoveryCodes' => $codes]);
    }

    #[Route('/me/2fa/recovery-codes', name: 'me_2fa_recovery_codes_list', methods: ['GET'])]
    public function listRecoveryCodes(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$user->isTotpEnabled()) {
            return $this->json(['error' => '2FA is not enabled.'], 409);
        }

        return $this->json([
            'recoveryCodes' => $this->setup->listRecoveryCodes($user),
        ]);
    }

    #[Route('/me/2fa/recovery-codes/reveal', name: 'me_2fa_recovery_codes_reveal', methods: ['POST'])]
    public function revealRecoveryCodes(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$user->isTotpEnabled()) {
            return $this->json(['error' => '2FA is not enabled.'], 409);
        }

        // Step-up auth: unused codes are real credentials. Cookie session
        // alone isn't enough — a stolen cookie shouldn't yield a portable
        // 2FA bypass that survives password rotation.
        $err = $this->verifier->verify($user, $this->jsonBody($request));
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }

        return $this->json([
            'recoveryCodes' => $this->setup->revealRecoveryCodes($user),
        ]);
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
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}
