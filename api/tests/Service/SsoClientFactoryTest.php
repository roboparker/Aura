<?php

namespace App\Tests\Service;

use App\Service\SsoClientFactory;
use App\Service\SsoConfig;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\Apple;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\Google;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Builds a configured league provider per SSO provider from the admin config
 * (#267). OIDC discovery for okta/auth0 is mocked.
 */
class SsoClientFactoryTest extends KernelTestCase
{
    private SsoConfig $config;
    private SsoClientFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $em->createQuery('DELETE FROM App\Entity\AppSetting')->execute();

        $this->config = self::getContainer()->get(SsoConfig::class);

        $discovery = (string) json_encode([
            'authorization_endpoint' => 'https://idp.example.com/authorize',
            'token_endpoint' => 'https://idp.example.com/token',
            'userinfo_endpoint' => 'https://idp.example.com/userinfo',
        ]);
        $mock = new MockHttpClient(static fn (): MockResponse => new MockResponse($discovery));
        $this->factory = new SsoClientFactory($this->config, $mock, new ArrayAdapter());
    }

    public function testScopes(): void
    {
        $this->assertSame(['read:user', 'user:email'], $this->factory->scopes('github'));
        $this->assertSame(['email', 'public_profile'], $this->factory->scopes('facebook'));
        // Unknown provider → OIDC default.
        $this->assertSame(['openid', 'email', 'profile'], $this->factory->scopes('mystery'));
    }

    public function testCreateConfiguredBuildsEachProvider(): void
    {
        $this->config->update('google', ['enabled' => true, 'clientId' => 'g', 'clientSecret' => 's']);
        $this->config->update('github', ['enabled' => true, 'clientId' => 'gh', 'clientSecret' => 's']);
        $this->config->update('facebook', ['enabled' => true, 'clientId' => 'fb', 'clientSecret' => 's']);
        $this->config->update('microsoft', ['enabled' => true, 'clientId' => 'ms', 'clientSecret' => 's', 'tenant' => 'common']);
        $this->config->update('apple', [
            'enabled' => true,
            'clientId' => 'com.example',
            'teamId' => 'TEAM',
            'keyId' => 'KEY',
            'key' => "-----BEGIN PRIVATE KEY-----\nMIGdummy\n-----END PRIVATE KEY-----",
        ]);
        $this->config->update('okta', ['enabled' => true, 'clientId' => 'ok', 'clientSecret' => 's', 'issuer' => 'https://okta.example.com']);
        $this->config->update('auth0', ['enabled' => true, 'clientId' => 'a0', 'clientSecret' => 's', 'issuer' => 'https://auth0.example.com']);

        $this->assertInstanceOf(Google::class, $this->factory->createConfigured('google', 'https://app/cb'));
        $this->assertInstanceOf(Github::class, $this->factory->createConfigured('github', 'https://app/cb'));
        $this->assertInstanceOf(Facebook::class, $this->factory->createConfigured('facebook', 'https://app/cb'));
        $this->assertInstanceOf(Azure::class, $this->factory->createConfigured('microsoft', 'https://app/cb'));
        $this->assertInstanceOf(Apple::class, $this->factory->createConfigured('apple', 'https://app/cb'));
        $this->assertInstanceOf(GenericProvider::class, $this->factory->createConfigured('okta', 'https://app/cb'));
        $this->assertInstanceOf(GenericProvider::class, $this->factory->createConfigured('auth0', 'https://app/cb'));
    }

    public function testCreateHonoursEnableToggle(): void
    {
        // Configured but NOT enabled → create() (login) is null, createConfigured is not.
        $this->config->update('google', ['enabled' => false, 'clientId' => 'g', 'clientSecret' => 's']);
        $this->assertNull($this->factory->create('google', 'https://app/cb'));
        $this->assertInstanceOf(Google::class, $this->factory->createConfigured('google', 'https://app/cb'));

        // Enabled → create() builds it.
        $this->config->update('google', ['enabled' => true, 'clientId' => 'g', 'clientSecret' => 's']);
        $this->assertInstanceOf(Google::class, $this->factory->create('google', 'https://app/cb'));
    }

    public function testUnconfiguredAndUnknownReturnNull(): void
    {
        $this->assertNull($this->factory->createConfigured('google', 'https://app/cb'));
        $this->assertNull($this->factory->createConfigured('dropbox', 'https://app/cb'));
    }
}
