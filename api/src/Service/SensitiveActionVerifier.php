<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Step-up confirmation for sensitive self-service actions (disable 2FA,
 * rotate or reveal recovery codes, change password).
 *
 * Branches on the user's 2FA status:
 *   - 2FA on  → expects `totpCode` in the body and validates it through
 *               the TOTP authenticator, consuming the per-user verify
 *               limiter (5/min) so brute-forcing the 6-digit code is
 *               throttled to the same shape as the login challenge.
 *   - 2FA off → expects `currentPassword` and re-hashes against the
 *               stored hash (legacy single-factor flow).
 *
 * Recovery override: when {@see TwoFactorRecoveryState::isPending()} is
 * true (the session just logged in with a backup code), the verifier
 * falls through to the password path even if 2FA is still flipped on —
 * the user has just proven they don't have the authenticator, so
 * demanding a TOTP would pin them out of the disable / re-enroll
 * endpoints. The password floor stays: a stolen backup code alone is
 * not enough to rotate 2FA.
 *
 * Returns null on success; on failure returns `[httpStatus, message]`
 * for the caller to surface as a `JsonResponse`.
 */
final class SensitiveActionVerifier
{
    public function __construct(
        private TwoFactorSetupService $setup,
        private UserPasswordHasherInterface $hasher,
        private RateLimiterFactoryInterface $twoFactorVerifyLimiter,
        private TwoFactorRecoveryState $recoveryState,
    ) {
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return array{int, string}|null
     */
    public function verify(User $user, array $body): ?array
    {
        if ($user->isTotpEnabled() && !$this->recoveryState->isPending()) {
            $limit = $this->twoFactorVerifyLimiter->create('user-' . $user->getId())->consume();
            if (!$limit->isAccepted()) {
                return [429, 'Too many attempts. Try again later.'];
            }

            $code = self::stringField($body, 'totpCode');
            if ('' === $code || !$this->setup->verifyCode($user, $code)) {
                return [400, 'Invalid authentication code.'];
            }

            return null;
        }

        $password = self::stringField($body, 'currentPassword');
        if (!$this->hasher->isPasswordValid($user, $password)) {
            return [400, 'Current password is incorrect.'];
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private static function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
