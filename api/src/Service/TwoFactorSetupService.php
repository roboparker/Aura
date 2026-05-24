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
     * Per-entry status for the security-panel list. `code` is the
     * plaintext for spent codes only — unused entries return null so the
     * UI keeps them masked. Spent codes can't re-authenticate (hash check
     * rejects them) so surfacing them is harmless.
     *
     * @return list<array{code: ?string, consumedAt: ?string}>
     */
    public function listRecoveryCodes(User $user): array
    {
        $out = [];
        foreach ($user->getRecoveryCodes() as $entry) {
            $code = null;
            if (null !== $entry['consumedAt']) {
                // Prefer the encrypted envelope (new path), fall back to
                // the legacy `consumedCode` field for entries written
                // before encrypted-at-generation shipped.
                if (isset($entry['encrypted'])) {
                    $code = $this->cipher->decrypt($entry['encrypted']);
                } else {
                    $code = $entry['consumedCode'] ?? null;
                }
            }
            $out[] = [
                'code' => $code,
                'consumedAt' => $entry['consumedAt'],
            ];
        }
        return $out;
    }

    /**
     * Decrypts every entry's plaintext for the GitHub-style "reveal codes"
     * flow. Callers must gate this behind a step-up password challenge —
     * unused codes are real credentials and shouldn't be surfaced from a
     * session whose only auth is a cookie.
     *
     * Legacy entries with no `encrypted` field (predating the reveal
     * feature) return null for `code`; the UI marks them as unavailable.
     *
     * @return list<array{code: ?string, consumedAt: ?string}>
     */
    public function revealRecoveryCodes(User $user): array
    {
        $out = [];
        foreach ($user->getRecoveryCodes() as $entry) {
            $code = null;
            if (isset($entry['encrypted'])) {
                $code = $this->cipher->decrypt($entry['encrypted']);
            } elseif (null !== $entry['consumedAt']) {
                $code = $entry['consumedCode'] ?? null;
            }
            $out[] = [
                'code' => $code,
                'consumedAt' => $entry['consumedAt'],
            ];
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
                // Envelope-encrypt the plaintext so the GitHub-style
                // reveal flow can re-display it after a step-up password
                // challenge. Hash stays the source of truth for
                // verification — encrypted is only ever read for display.
                'encrypted' => $this->cipher->encrypt($code),
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
