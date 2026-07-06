<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\SsoIdentity;
use App\Entity\User;
use App\Service\SsoConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SSO controller endpoints outside the OAuth callback (#267): start/connect
 * redirects, callback error branches, identity listing + unlink.
 */
class SsoEndpointsTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\SsoIdentity')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\AppSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    private function configureGoogle(): void
    {
        $config = self::getContainer()->get(SsoConfig::class);
        $config->update('google', ['enabled' => true, 'clientId' => 'gid.apps', 'clientSecret' => 'secret']);
    }

    public function testStartRedirectsToProviderWhenConfigured(): void
    {
        $this->configureGoogle();
        $client = static::createClient();
        $client->request('GET', '/auth/sso/google/start?next=/tasks');

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('accounts.google.com', (string) $client->getKernelBrowser()->getResponse()->headers->get("Location"));
    }

    public function testStartUnknownProviderIs404(): void
    {
        static::createClient()->request('GET', '/auth/sso/dropbox/start');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testConnectRedirectsForAuthedUser(): void
    {
        $this->configureGoogle();
        $client = static::createClient();
        $client->loginUser($this->createUser('c@example.com', withPassword: true));
        $client->request('GET', '/me/sso/google/connect');

        $this->assertResponseStatusCodeSame(302);
    }

    public function testCheckUnknownProviderRedirectsToSigninError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/sso/dropbox/check?state=x&code=y');

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/signin?sso_error=unknown_provider', (string) $client->getKernelBrowser()->getResponse()->headers->get("Location"));
    }

    public function testCheckBadStateRedirects(): void
    {
        $this->configureGoogle();
        $client = static::createClient();
        // No start() called → no stored state → any state mismatches.
        $client->request('GET', '/auth/sso/google/check?state=forged&code=y');

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('sso_error=bad_state', (string) $client->getKernelBrowser()->getResponse()->headers->get("Location"));
    }

    public function testIdentitiesListsLinkedProviders(): void
    {
        $this->configureGoogle();
        $user = $this->createUser('id@example.com', withPassword: true);
        $this->linkIdentity($user, 'google', 'g-1', 'id@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $body = $client->request('GET', '/me/sso/identities')->toArray();

        $identities = $body['identities'] ?? [];
        $this->assertIsArray($identities);
        $this->assertCount(1, $identities);
        $this->assertTrue($body['hasPassword'] ?? null);
        $available = $body['available'] ?? [];
        $this->assertIsArray($available);
        $this->assertContains('google', $available);
    }

    public function testUnlinkSucceedsWhenPasswordPresent(): void
    {
        $user = $this->createUser('u@example.com', withPassword: true);
        $this->linkIdentity($user, 'google', 'g-9', 'u@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('DELETE', '/me/sso/google');
        $this->assertResponseIsSuccessful();
    }

    public function testUnlinkNotLinkedIs404(): void
    {
        $user = $this->createUser('n@example.com', withPassword: true);
        $client = static::createClient();
        $client->loginUser($user);
        $client->request('DELETE', '/me/sso/github');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnlinkOnlySignInMethodIsRejected(): void
    {
        // No password + single identity → can't remove the only way in.
        $user = $this->createUser('only@example.com', withPassword: false);
        $this->linkIdentity($user, 'google', 'g-only', 'only@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('DELETE', '/me/sso/google');
        $this->assertResponseStatusCodeSame(422);
    }

    private function linkIdentity(User $user, string $provider, string $subject, string $email): void
    {
        $identity = (new SsoIdentity($provider, $subject))->setEmail($email);
        $user->addSsoIdentity($identity);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }

    private function createUser(string $email, bool $withPassword): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        if ($withPassword) {
            $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
            $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
