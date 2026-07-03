<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OAuthConnection;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Stores and refreshes OAuth tokens for account connections (#581). Tokens are
 * held on the {@see OAuthConnection} as encrypted envelopes ({@see
 * TwoFactorSecretCipher}); this is the only place that encrypts/decrypts them.
 * {@see accessToken()} always returns a currently-valid token, transparently
 * refreshing via the refresh token when it's near expiry.
 */
final class OAuthTokenService
{
    public function __construct(
        private readonly SsoClientFactory $factory,
        private readonly TwoFactorSecretCipher $cipher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Persist the tokens from a fresh grant onto the connection (encrypted). */
    public function store(OAuthConnection $connection, AccessTokenInterface $token): void
    {
        $connection->setAccessToken($this->cipher->encrypt($token->getToken()));

        $refresh = $token->getRefreshToken();
        // Google only returns a refresh token on the first consent — keep the
        // existing one when a refresh response omits it.
        if (is_string($refresh) && '' !== $refresh) {
            $connection->setRefreshToken($this->cipher->encrypt($refresh));
        }

        $expires = $token->getExpires();
        $connection->setExpiresAt(
            is_int($expires) ? (new \DateTimeImmutable())->setTimestamp($expires) : null,
        );
    }

    /**
     * A valid access token for the connection, refreshing first if it's within
     * two minutes of expiry. Null if there's no way to get one (no refresh
     * token, refresh failed, provider unconfigured).
     */
    public function accessToken(OAuthConnection $connection): ?string
    {
        $expiresAt = $connection->getExpiresAt();
        $stale = null !== $expiresAt && $expiresAt <= new \DateTimeImmutable('+2 minutes');
        if ($stale && !$this->refresh($connection)) {
            return null;
        }

        return $this->cipher->decrypt($connection->getAccessToken());
    }

    private function refresh(OAuthConnection $connection): bool
    {
        $refreshEnc = $connection->getRefreshToken();
        if (null === $refreshEnc) {
            return false;
        }
        $refresh = $this->cipher->decrypt($refreshEnc);
        if (null === $refresh) {
            return false;
        }
        // redirectUri is irrelevant for a refresh grant.
        $client = $this->factory->createConfigured($connection->getProvider(), '');
        if (null === $client) {
            return false;
        }

        try {
            $token = $client->getAccessToken('refresh_token', ['refresh_token' => $refresh]);
            $this->store($connection, $token);
            $this->em->flush();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
