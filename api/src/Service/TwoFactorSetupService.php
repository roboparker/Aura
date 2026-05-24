<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;

/**
 * Coordinates the User-side write path for 2FA: secret generation, secret
 * encryption, recovery-code rotation, enable/disable. Keeping this off the
 * entity itself means {@see User} stays free of service dependencies and
 * the controller stays a thin orchestrator.
 */
final class TwoFactorSetupService
{
    public const RECOVERY_CODE_COUNT = 10;
    private const RECOVERY_CODE_BYTES = 6;

    public function __construct(
        private TotpAuthenticatorInterface $totp,
        private TwoFactorSecretCipher $cipher,
    ) {
    }

    /**
     * Per-entry status for the security-panel list: enough to render
     * "consumed N of M" with strikethrough, but no plaintext — codes are
     * hash-only on disk and never recoverable post-generation.
     *
     * @return list<array{consumedAt: ?string}>
     */
    public function listRecoveryCodes(User $user): array
    {
        $out = [];
        foreach ($user->getRecoveryCodes() as $entry) {
            $out[] = ['consumedAt' => $entry['consumedAt']];
        }
        return $out;
    }

    /**
     * Generates a new base32 TOTP secret and stores it (encrypted) on the
     * user. Idempotent for repeated calls during the setup flow — a user
     * who never confirms verify just leaves the previous unconfirmed
     * secret behind, which we always overwrite.
     */
    public function startSetup(User $user): string
    {
        $secret = $this->totp->generateSecret();
        $user->setTotpSecretEncrypted($this->cipher->encrypt($secret));
        $user->setTotpSecretCache($secret);
        // Defensive: never leave 2FA "enabled" with a freshly-rotated
        // unconfirmed secret. The verify step flips this back on.
        $user->setTotpEnabled(false);
        $user->setRecoveryCodes([]);
        return $secret;
    }

    public function buildProvisioningUri(User $user): string
    {
        return $this->totp->getQRContent($user);
    }

    public function verifyCode(User $user, string $code): bool
    {
        return $this->totp->checkCode($user, $code);
    }

    /**
     * Generates fresh plaintext recovery codes, persists them on the user
     * as `{hash, consumedAt}` pairs, and returns the plaintext list to the
     * caller. Plaintext is surfaced exactly once — the user is expected to
     * save it; consumed entries are kept (with `consumedAt` set) so the UI
     * can show "used N of M" with strikethrough.
     *
     * @return string[] plaintext recovery codes (e.g. "a3f9-1c8b-22e0")
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $plain = [];
        $entries = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = $this->generatePlaintextCode();
            $plain[] = $code;
            $entries[] = [
                'hash' => hash('sha256', $code),
                'consumedAt' => null,
            ];
        }
        $user->setRecoveryCodes($entries);
        return $plain;
    }

    public function disable(User $user): void
    {
        $user->setTotpEnabled(false);
        $user->setTotpSecretEncrypted(null);
        $user->setTotpSecretCache(null);
        $user->setRecoveryCodes([]);
    }

    private function generatePlaintextCode(): string
    {
        // Three groups of 4 hex chars separated by dashes — easy to read
        // off a printed sheet without confusing 0/O or 1/l.
        $hex = bin2hex(random_bytes(self::RECOVERY_CODE_BYTES));
        return substr($hex, 0, 4) . '-' . substr($hex, 4, 4) . '-' . substr($hex, 8, 4);
    }
}
