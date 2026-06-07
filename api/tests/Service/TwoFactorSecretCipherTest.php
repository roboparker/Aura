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
        // Flip a character in the base64 body — the AEAD tag check must fail.
        $tampered = substr($envelope, 0, -2) . ($envelope[-2] === 'A' ? 'B' : 'A') . $envelope[-1];

        $this->assertNull($cipher->decrypt($tampered));
    }

    public function testCannotDecryptWithADifferentKey(): void
    {
        $envelope = $this->cipher('secret-one')->encrypt('JBSWY3DPEHPK3PXP');

        $this->assertNull($this->cipher('secret-two')->decrypt($envelope));
    }
}
