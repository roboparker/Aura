<?php

declare(strict_types=1);

namespace App\Service;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Apple;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\Google;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Builds a configured league OAuth2 provider for a given social/enterprise
 * provider from the admin-managed {@see SsoConfig} (DB), at request time.
 * Replaces knp's build-time ClientRegistry so credentials can live in the DB.
 * Okta/Auth0 are generic OIDC providers resolved via discovery.
 *
 * Not `final` so tests can substitute a stub provider (the OAuth callback can't
 * otherwise be exercised without a live provider).
 */
class SsoClientFactory
{
    /** OAuth scopes per provider (space/comma joining is the provider's own). */
    public const SCOPES = [
        'google' => ['openid', 'email', 'profile'],
        'microsoft' => ['openid', 'email', 'profile'],
        'github' => ['read:user', 'user:email'],
        'facebook' => ['email', 'public_profile'],
        'apple' => ['name', 'email'],
        'okta' => ['openid', 'email', 'profile'],
        'auth0' => ['openid', 'email', 'profile'],
    ];

    public function __construct(
        private readonly SsoConfig $config,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @return list<string> */
    public function scopes(string $provider): array
    {
        return self::SCOPES[$provider] ?? ['openid', 'email', 'profile'];
    }

    /** The provider for sign-in — null unless enabled AND configured. */
    public function create(string $provider, string $redirectUri): ?AbstractProvider
    {
        $c = $this->config->resolve($provider);

        return null === $c ? null : $this->build($provider, $c, $redirectUri);
    }

    /**
     * The provider built from configured credentials regardless of the login
     * toggle — for OAuth account connections (#581).
     */
    public function createConfigured(string $provider, string $redirectUri): ?AbstractProvider
    {
        $c = $this->config->credentials($provider);

        return null === $c ? null : $this->build($provider, $c, $redirectUri);
    }

    /**
     * @param array<array-key, mixed> $c
     */
    private function build(string $provider, array $c, string $redirectUri): ?AbstractProvider
    {
        $clientId = is_string($c['clientId'] ?? null) ? $c['clientId'] : '';
        $clientSecret = is_string($c['clientSecret'] ?? null) ? $c['clientSecret'] : '';
        $base = ['clientId' => $clientId, 'clientSecret' => $clientSecret, 'redirectUri' => $redirectUri];

        return match ($provider) {
            'google' => new Google($base),
            'github' => new Github($base),
            'facebook' => new Facebook($base + ['graphApiVersion' => 'v19.0']),
            'microsoft' => new Azure($base + [
                'tenant' => is_string($c['tenant'] ?? null) && '' !== $c['tenant'] ? $c['tenant'] : 'common',
            ]),
            'apple' => $this->apple($c, $clientId, $redirectUri),
            'okta', 'auth0' => $this->oidc($c, $clientId, $clientSecret, $redirectUri),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $c
     */
    private function apple(array $c, string $clientId, string $redirectUri): Apple
    {
        // The provider needs the .p8 as a file path; materialise the DB-stored
        // key to a content-addressed temp file (reused across requests, so it
        // doesn't accumulate and there's no write race for the same key).
        $keyPem = is_string($c['key'] ?? null) ? $c['key'] : '';
        $path = sys_get_temp_dir() . '/apple_sso_' . md5($keyPem) . '.p8';
        if (!is_file($path)) {
            file_put_contents($path, $keyPem);
        }

        return new Apple([
            'clientId' => $clientId,
            'teamId' => is_string($c['teamId'] ?? null) ? $c['teamId'] : '',
            'keyFileId' => is_string($c['keyId'] ?? null) ? $c['keyId'] : '',
            'keyFilePath' => $path,
            'redirectUri' => $redirectUri,
        ]);
    }

    /**
     * @param array<array-key, mixed> $c
     */
    private function oidc(array $c, string $clientId, string $clientSecret, string $redirectUri): GenericProvider
    {
        $issuer = is_string($c['issuer'] ?? null) ? $c['issuer'] : '';
        $endpoints = $this->discover($issuer);

        return new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $redirectUri,
            'urlAuthorize' => $endpoints['authorization_endpoint'],
            'urlAccessToken' => $endpoints['token_endpoint'],
            'urlResourceOwnerDetails' => $endpoints['userinfo_endpoint'],
            'responseResourceOwnerId' => 'sub',
            'scopes' => ['openid', 'email', 'profile'],
            'scopeSeparator' => ' ',
        ]);
    }

    /**
     * OIDC discovery for the issuer, cached for an hour.
     *
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}
     */
    private function discover(string $issuer): array
    {
        $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';

        /** @var array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string} $result */
        $result = $this->cache->get('sso_oidc_' . md5($url), function (ItemInterface $item) use ($url): array {
            $item->expiresAfter(3600);
            $data = $this->httpClient->request('GET', $url)->toArray();

            $get = static fn (string $k): string => isset($data[$k]) && is_string($data[$k]) ? $data[$k] : '';

            return [
                'authorization_endpoint' => $get('authorization_endpoint'),
                'token_endpoint' => $get('token_endpoint'),
                'userinfo_endpoint' => $get('userinfo_endpoint'),
            ];
        });

        return $result;
    }
}
