<?php

namespace App\Tests\Api;

use App\Controller\OAuthConnectionController;
use App\Entity\OAuthConnection;
use App\Entity\User;
use App\Service\CalendarSubscriptionManager;
use App\Service\OAuthTokenService;
use App\Service\SsoConfig;
use App\Tests\Support\StubSsoClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * OAuth account-connection callback (#581) — token exchange + connection upsert
 * — driven with a stubbed league provider so no network is hit.
 */
class OAuthConnectionCallbackTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM App\Entity\OAuthConnection')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCallbackStoresConnection(): void
    {
        $user = new User();
        $user->setEmail('conn@example.com');
        $user->setGivenName('C');
        $user->setFamilyName('U');
        $user->setPersonalizedColor('#0369a1');
        $this->em->persist($user);
        $this->em->flush();

        $owner = $this->createMock(ResourceOwnerInterface::class);
        $owner->method('toArray')->willReturn(['sub' => 'ext-1', 'email' => 'conn@example.com']);
        $provider = $this->createMock(AbstractProvider::class);
        $provider->method('getAccessToken')->willReturn(new AccessToken([
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'expires_in' => 3600,
        ]));
        $provider->method('getResourceOwner')->willReturn($owner);

        $container = self::getContainer();
        $factory = new StubSsoClientFactory(
            $container->get(SsoConfig::class),
            new MockHttpClient(),
            new ArrayAdapter(),
            $provider,
        );

        $controller = new OAuthConnectionController(
            $container->get(SsoConfig::class),
            $factory,
            $container->get(OAuthTokenService::class),
            $this->em,
            $container->get(CalendarSubscriptionManager::class),
        );
        $controller->setContainer($container);

        $request = Request::create('/me/connections/google/check?state=st&code=abc');
        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth_conn_state', 'st');
        $request->setSession($session);

        $response = $controller->check('google', $request, $user);

        $this->assertStringContainsString('connected=google', $response->getTargetUrl());
        $this->em->clear();
        $connection = $this->em->getRepository(OAuthConnection::class)->findOneBy(['provider' => 'google']);
        $this->assertNotNull($connection);
        $this->assertSame('conn@example.com', $connection->getEmail());
        $this->assertNotNull($connection->getExpiresAt());
    }
}
