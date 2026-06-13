<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TwoFactorSecretCipher;
use PHPUnit\Framework\TestCase;

class TwoFactorSecretCipherTest extends TestCase
{
    private function cipher(string $secret = 'test-app-secret'): TwoFactorSecretCipher
    {
        return new TwoFactorSecretCipher($secret);
    }

    public function testRoundTripsThePlaintext(): void
    {
        $cipher = $this->cipher();
        $secret = 'JBSWY3DPEHPK3PXP';

        $this->assertSame($secret, $cipher->decrypt($cipher->encrypt($secret)));
    }

    public function testEncryptionIsNonDeterministic(): void
    {
        $cipher = $this->cipher();

        // Random nonce per call → two envelopes of the same plaintext differ,
        // but both still decrypt back to it.
        $a = $cipher->encrypt('same');
        $b = $cipher->encrypt('same');
        $this->assertNotSame($a, $b);
        $this->assertSame('same', $cipher->decrypt($a));
        $this->assertSame('same', $cipher->decrypt($b));
    }

    public function testRejectsAnEnvelopeWithoutThePrefix(): void
    {
        $this->assertNull($this->cipher()->decrypt('not-an-envelope'));
    }

    public function testRejectsTamperedCiphertext(): void
    {
        $cipher = $this->cipher();
        $envelope = $cipher->encrypt('JBSWY3DPEHPK3PXP');

        // Flip a bit in the last byte of the blob (the AEAD tag) — decryption
        // must fail. We decode/flip/re-encode rather than poking the base64
        // string directly: the char next to the '=' padding only carries its
        // top bits into the plaintext, so flipping it can be a decode no-op
        // that leaves the ciphertext intact (a source of flakiness).
        $blob = base64_decode(substr($envelope, strlen('tfa1:')), true);
        $this->assertNotFalse($blob);
        $blob[strlen($blob) - 1] = $blob[strlen($blob) - 1] ^ "\x01";
        $tampered = 'tfa1:' . base64_encode($blob);

        $this->assertNull($cipher->decrypt($tampered));
    }

    public function testCannotDecryptWithADifferentKey(): void
    {
        $envelope = $this->cipher('secret-one')->encrypt('JBSWY3DPEHPK3PXP');

        $this->assertNull($this->cipher('secret-two')->decrypt($envelope));
    }
}
