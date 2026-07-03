<?php

namespace App\Tests\Support;

use App\Service\SsoClientFactory;
use App\Service\SsoConfig;
use League\OAuth2\Client\Provider\AbstractProvider;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Test double: returns a caller-supplied stub provider from create() /
 * createConfigured(), so the OAuth callback flows can be exercised without a
 * live provider. (SsoClientFactory is non-final for exactly this.)
 */
class StubSsoClientFactory extends SsoClientFactory
{
    public function __construct(
        SsoConfig $config,
        HttpClientInterface $httpClient,
        CacheInterface $cache,
        private readonly AbstractProvider $stub,
    ) {
        parent::__construct($config, $httpClient, $cache);
    }

    public function create(string $provider, string $redirectUri): ?AbstractProvider
    {
        return $this->stub;
    }

    public function createConfigured(string $provider, string $redirectUri): ?AbstractProvider
    {
        return $this->stub;
    }
}
