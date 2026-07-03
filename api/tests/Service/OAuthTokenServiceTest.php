<?php

namespace App\Tests\Service;

use App\Entity\OAuthConnection;
use App\Service\OAuthTokenService;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Token storage crypto + staleness logic for OAuth connections (#581). The
 * refresh path needs a live provider, so these cover the parts that don't:
 * encryption at rest, the decrypt round-trip, and the near-expiry short-circuit.
 */
class OAuthTokenServiceTest extends KernelTestCase
{
    private function service(): OAuthTokenService
    {
        self::bootKernel();

        return self::getContainer()->get(OAuthTokenService::class);
    }

    public function testStoreEncryptsAndAccessTokenDecrypts(): void
    {
        $service = $this->service();
        $connection = new OAuthConnection('google');
        $token = new AccessToken([
            'access_token' => 'plain-access-123',
            'refresh_token' => 'plain-refresh-456',
            'expires_in' => 3600,
        ]);

        $service->store($connection, $token);

        // Stored envelope is encrypted, not the raw token.
        $this->assertNotSame('plain-access-123', $connection->getAccessToken());
        $this->assertNotSame('', $connection->getAccessToken());
        $this->assertNotNull($connection->getRefreshToken());
        $this->assertNotSame('plain-refresh-456', $connection->getRefreshToken());
        $this->assertNotNull($connection->getExpiresAt());

        // A comfortably-fresh token decrypts back without touching a provider.
        $this->assertSame('plain-access-123', $service->accessToken($connection));
    }

    public function testStaleTokenWithoutRefreshTokenReturnsNull(): void
    {
        $service = $this->service();
        $connection = new OAuthConnection('google');
        // Expires almost immediately and carries no refresh token → nothing to
        // refresh with, so accessToken() reports it can't produce a valid one.
        $token = new AccessToken(['access_token' => 'about-to-expire', 'expires_in' => 1]);

        $service->store($connection, $token);

        $this->assertNull($connection->getRefreshToken());
        $this->assertNull($service->accessToken($connection));
    }
}
