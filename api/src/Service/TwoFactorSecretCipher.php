<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Symmetric envelope around the TOTP secret stored on User.
 *
 * Why encrypt at all (vs. just hashing like passwords / recovery codes)?
 * TOTP verification needs the original base32 secret to recompute the
 * 30-second code window — there is no equivalent of a password hash.
 *
 * The key is derived from APP_SECRET. Rotating APP_SECRET would invalidate
 * existing 2FA setups; that's an acceptable tradeoff for the simplicity of
 * not needing a separate KMS in dev.
 */
final class TwoFactorSecretCipher
{
    private const PREFIX = 'tfa1:';
    private string $key;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        string $appSecret,
    ) {
        // Empty APP_SECRET still derives a (deterministic, weak) key — the
        // alternative would be to hard-fail at boot, which would break dev
        // environments that haven't filled in .env.local. Production deploys
        // must override APP_SECRET; we don't try to enforce that here.
        $this->key = hash_hkdf('sha256', $appSecret, 32, 'madori-totp-secret-v1');
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $envelope): ?string
    {
        if (!str_starts_with($envelope, self::PREFIX)) {
            return null;
        }
        $blob = base64_decode(substr($envelope, strlen(self::PREFIX)), true);
        if (false === $blob || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        return false === $plaintext ? null : $plaintext;
    }
}
