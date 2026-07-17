<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the admin-only Sentry verification triggers (SentryTestController).
 * These endpoints exist to confirm the Sentry pipeline once a DSN is set — the
 * SDK itself is a no-op in the test env (blank SENTRY_DSN), so these assertions
 * only exercise the routing, the admin gate, and the intentional failures.
 */
class SentryTestControllerTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testEndpointsRequireAdmin(): void
    {
        $user = $this->createTestUser('plain@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('GET', '/admin/sentry-test/error');
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/sentry-test/worker');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminErrorEndpointRaisesAnException(): void
    {
        $admin = $this->createTestUser('admin@example.com', ['ROLE_ADMIN']);

        $client = static::createClient();
        $client->loginUser($admin);

        // The controller throws on purpose; the kernel catches it (the default
        // for functional tests) and renders a 500 that Sentry's error listener
        // would capture in a DSN-configured environment.
        $client->request('GET', '/admin/sentry-test/error');
        $this->assertResponseStatusCodeSame(500);
    }

    public function testAdminWorkerEndpointDispatchesFailingJob(): void
    {
        $admin = $this->createTestUser('admin@example.com', ['ROLE_ADMIN']);

        $client = static::createClient();
        $client->loginUser($admin);

        // In prod the async (Doctrine) transport returns 202 immediately and the
        // worker fails the job later. In the test env the `async` transport is
        // sync://, so the intentionally-failing handler surfaces synchronously
        // as a 500 — which is exactly what exercises the handler code path.
        $client->request('POST', '/admin/sentry-test/worker');
        $this->assertResponseStatusCodeSame(500);
    }

    /**
     * @param list<string> $roles
     */
    private function createTestUser(string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
