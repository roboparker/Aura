<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Admin impersonation via the firewall's switch_user feature.
 *
 * Start: any main-firewall request carrying ?_switch_user=<email>.
 * Stop: ?_switch_user=_exit. The /api/me payload surfaces an `impersonator`
 * block so the PWA can show the banner + "Stop impersonation" control.
 */
class ImpersonationTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAdminCanImpersonateAndPayloadSurfacesImpersonator(): void
    {
        $admin = $this->createTestUser('admin@example.com', 'Password123!@#', ['ROLE_ADMIN']);
        $this->createTestUser('member@example.com');

        $client = static::createClient();
        // switch_user replies with a 302 to the same URL minus ?_switch_user;
        // follow it (as a real fetch would) to land on the swapped /api/me.
        $client->getKernelBrowser()->followRedirects();
        $client->loginUser($admin);

        $client->request('GET', '/api/me?_switch_user=member@example.com');
        $this->assertResponseIsSuccessful();

        $body = $this->body($client);
        $this->assertSame('member@example.com', $this->stringField($body, 'email'));

        $impersonator = $body['impersonator'];
        $this->assertIsArray($impersonator);
        $this->assertSame('admin@example.com', $this->stringField($impersonator, 'email'));
        $this->assertSame((string) $admin->getId(), $this->stringField($impersonator, 'id'));
    }

    public function testNormalSessionHasNullImpersonator(): void
    {
        $admin = $this->createTestUser('admin@example.com', 'Password123!@#', ['ROLE_ADMIN']);

        $client = static::createClient();
        // switch_user replies with a 302 to the same URL minus ?_switch_user;
        // follow it (as a real fetch would) to land on the swapped /api/me.
        $client->getKernelBrowser()->followRedirects();
        $client->loginUser($admin);

        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->body($client)['impersonator']);
    }

    public function testImpersonatedSessionLosesAdminAccess(): void
    {
        $admin = $this->createTestUser('admin@example.com', 'Password123!@#', ['ROLE_ADMIN']);
        $this->createTestUser('member@example.com');

        $client = static::createClient();
        // switch_user replies with a 302 to the same URL minus ?_switch_user;
        // follow it (as a real fetch would) to land on the swapped /api/me.
        $client->getKernelBrowser()->followRedirects();
        $client->loginUser($admin);

        // Switch into the non-admin member...
        $client->request('GET', '/api/me?_switch_user=member@example.com');
        $this->assertResponseIsSuccessful();

        // ...and the impersonated session can no longer reach admin surfaces:
        // the SwitchUserToken carries the member's roles, not ROLE_ADMIN.
        $client->request('GET', '/admin/waitlist');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testExitRestoresAdmin(): void
    {
        $admin = $this->createTestUser('admin@example.com', 'Password123!@#', ['ROLE_ADMIN']);
        $this->createTestUser('member@example.com');

        $client = static::createClient();
        // switch_user replies with a 302 to the same URL minus ?_switch_user;
        // follow it (as a real fetch would) to land on the swapped /api/me.
        $client->getKernelBrowser()->followRedirects();
        $client->loginUser($admin);

        $client->request('GET', '/api/me?_switch_user=member@example.com');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/api/me?_switch_user=_exit');
        $this->assertResponseIsSuccessful();

        $body = $this->body($client);
        $this->assertSame('admin@example.com', $this->stringField($body, 'email'));
        $this->assertNull($body['impersonator']);

        // And admin access is back.
        $client->request('GET', '/admin/waitlist');
        $this->assertResponseIsSuccessful();
    }

    public function testNonAdminCannotImpersonate(): void
    {
        $member = $this->createTestUser('member@example.com');
        $this->createTestUser('victim@example.com');

        $client = static::createClient();
        // switch_user replies with a 302 to the same URL minus ?_switch_user;
        // follow it (as a real fetch would) to land on the swapped /api/me.
        $client->getKernelBrowser()->followRedirects();
        $client->loginUser($member);

        $client->request('GET', '/api/me?_switch_user=victim@example.com');
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function body(Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        return $response->toArray(false);
    }

    /**
     * @param list<string> $roles
     */
    private function createTestUser(string $email, string $plainPassword = 'Password123!@#', array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
