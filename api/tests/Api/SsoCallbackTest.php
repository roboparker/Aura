<?php

namespace App\Tests\Api;

use App\Controller\SsoController;
use App\Entity\SsoIdentity;
use App\Entity\User;
use App\Service\AvatarColorService;
use App\Service\PersonalSpaceProvisioner;
use App\Service\SsoConfig;
use App\Service\WaitlistSettings;
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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The SSO OAuth callback happy paths (#267) — sign-in provisioning and account
 * linking — driven with a stubbed league provider so no network is hit.
 */
class SsoCallbackTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM App\Entity\SsoIdentity')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    /**
     * @param array<string, mixed> $ownerData
     */
    private function controller(array $ownerData): SsoController
    {
        $owner = $this->createMock(ResourceOwnerInterface::class);
        $owner->method('toArray')->willReturn($ownerData);

        $provider = $this->createMock(AbstractProvider::class);
        $provider->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'tok']));
        $provider->method('getResourceOwner')->willReturn($owner);

        $container = self::getContainer();
        $factory = new StubSsoClientFactory(
            $container->get(SsoConfig::class),
            new MockHttpClient(),
            new ArrayAdapter(),
            $provider,
        );

        $controller = new SsoController(
            $container->get(SsoConfig::class),
            $factory,
            $this->em,
            $container->get(TokenStorageInterface::class),
            $container->get(AvatarColorService::class),
            $container->get(PersonalSpaceProvisioner::class),
            $container->get(WaitlistSettings::class),
            $container->get(HttpClientInterface::class),
        );
        $controller->setContainer($container);

        return $controller;
    }

    private function callbackRequest(bool $linkMode): Request
    {
        $request = Request::create('/auth/sso/google/check?state=st&code=abc');
        $session = new Session(new MockArraySessionStorage());
        $session->set('sso_oauth_state', 'st');
        if ($linkMode) {
            $session->set('sso_link', true);
        }
        $request->setSession($session);

        return $request;
    }

    public function testCallbackProvisionsAndSignsIn(): void
    {
        $controller = $this->controller([
            'sub' => 'g-100',
            'email' => 'new@example.com',
            'email_verified' => true,
            'given_name' => 'New',
            'family_name' => 'Person',
        ]);

        $controller->check('google', $this->callbackRequest(false));

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        $this->assertNotNull($user);
        $this->assertNotNull(
            $this->em->getRepository(SsoIdentity::class)->findOneBy(['provider' => 'google', 'subject' => 'g-100']),
        );
    }

    public function testCallbackLinksToCurrentUser(): void
    {
        $user = new User();
        $user->setEmail('me@example.com');
        $user->setGivenName('Me');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $this->em->persist($user);
        $this->em->flush();

        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $controller = $this->controller([
            'sub' => 'g-link',
            'email' => 'me@example.com',
            'email_verified' => true,
        ]);

        $response = $controller->check('google', $this->callbackRequest(true));

        $this->assertStringContainsString('/settings/security', $response->getTargetUrl());
        $this->em->clear();
        $this->assertNotNull(
            $this->em->getRepository(SsoIdentity::class)->findOneBy(['provider' => 'google', 'subject' => 'g-link']),
        );
    }
}
