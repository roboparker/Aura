<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\AdminActionLog;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Site-admin deletion of somebody else's data — the only place in the product
 * where a permanent, un-undoable delete is possible.
 *
 * The tests are mostly about the guards: what an admin has to clear before the
 * destructive path opens, and that every attempt lands in the audit log
 * whichever way it goes.
 */
class AdminDeletionTest extends ApiTestCase
{
    private const PASSWORD = 'Password123!@#';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\AdminActionLog')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\RestoreToken')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\OrganizationMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Organization')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceRole')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testNonAdminIsRefused(): void
    {
        $plain = $this->createUser('plain@example.com');
        $victim = $this->createUser('victim@example.com');

        $client = static::createClient();
        $client->loginUser($plain);
        $client->request('POST', '/admin/deletions', [
            'json' => $this->payload('account', (string) $victim->getId(), 'victim@example.com'),
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testImmediateDeletionRemovesTheAccountAndAudits(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $victim = $this->createUser('spammer@example.com');
        $victimId = (string) $victim->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/admin/deletions', [
            'json' => $this->payload('account', $victimId, 'spammer@example.com', immediate: true),
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $em = $this->em();
        $em->clear();
        $this->assertNull(
            $em->getRepository(User::class)->findOneBy(['email' => 'spammer@example.com']),
            'an immediate admin deletion should skip the grace period entirely',
        );

        $log = $em->getRepository(AdminActionLog::class)->findOneBy([]);
        $this->assertNotNull($log, 'the action must be audited');
        $this->assertSame(AdminActionLog::ACTION_DELETE_IMMEDIATE, $log->getAction());
        $this->assertSame('admin@example.com', $log->getActorEmail());
        $this->assertSame('spammer@example.com', $log->getTargetLabel());
        $this->assertNotSame('', $log->getReason());
    }

    public function testScheduledDeletionLeavesTheAccountRestorable(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $victim = $this->createUser('mistake@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/admin/deletions', [
            'json' => $this->payload('account', (string) $victim->getId(), 'mistake@example.com'),
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        // The default path is the reversible one.
        $em = $this->em();
        $em->clear();
        $pending = $em->getRepository(User::class)->findOneBy(['email' => 'mistake@example.com']);
        $this->assertNotNull($pending);
        $this->assertTrue($pending->isDeleted());

        $log = $em->getRepository(AdminActionLog::class)->findOneBy([]);
        $this->assertNotNull($log);
        $this->assertSame(AdminActionLog::ACTION_DELETE_SCHEDULED, $log->getAction());
    }

    public function testReasonIsRequired(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $victim = $this->createUser('victim@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $payload = $this->payload('account', (string) $victim->getId(), 'victim@example.com');
        unset($payload['reason']);
        $client->request('POST', '/admin/deletions', [
            'json' => $payload,
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        // The audit row is read by a human months later; an unexplained
        // deletion is barely better than an unlogged one.
        $this->assertResponseStatusCodeSame(422);
    }

    public function testConfirmationMustMatchTheTargetLabel(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $victim = $this->createUser('victim@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $payload = $this->payload('account', (string) $victim->getId(), 'victim@example.com');
        $payload['confirm'] = 'not-the-email';
        $client->request('POST', '/admin/deletions', [
            'json' => $payload,
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testAdminCannotDeleteTheirOwnAccountHere(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/admin/deletions', [
            'json' => $this->payload('account', (string) $admin->getId(), 'admin@example.com', immediate: true),
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        // Self-deletion belongs in Settings → Danger zone, which asks the
        // churn survey and confirms differently.
        $this->assertResponseStatusCodeSame(409);
    }

    public function testWrongStepUpCredentialIsRefused(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $victim = $this->createUser('victim@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $payload = $this->payload('account', (string) $victim->getId(), 'victim@example.com', immediate: true);
        $payload['currentPassword'] = 'wrong-password';
        $client->request('POST', '/admin/deletions', [
            'json' => $payload,
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->em()->clear();
        $this->assertNotNull(
            $this->em()->getRepository(User::class)->findOneBy(['email' => 'victim@example.com']),
        );
    }

    public function testAssetsEndpointReportsSolelyOwnedOrganizations(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $owner = $this->createUser('owner@example.com');
        $org = (new Organization())
            ->setName('Solo Corp')
            ->setSlug('o-' . bin2hex(random_bytes(4)))
            ->setCreatedBy($owner);
        $org->addMember($owner, Organization::ROLE_OWNER);
        $this->entityManager->persist($org);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);
        $body = $client->request(
            'GET',
            '/admin/deletions/user-assets/' . $owner->getId(),
        )->toArray();

        $organizations = $body['organizations'];
        $this->assertIsArray($organizations);
        $this->assertCount(1, $organizations);
        $first = $organizations[0];
        $this->assertIsArray($first);
        $this->assertSame('Solo Corp', $first['name']);
    }

    public function testAuditLogIsReadable(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $victim = $this->createUser('victim@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/admin/deletions', [
            'json' => $this->payload('account', (string) $victim->getId(), 'victim@example.com'),
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $body = $client->request('GET', '/admin/deletions/log')->toArray();
        $entries = $body['entries'];
        $this->assertIsArray($entries);
        $this->assertCount(1, $entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $targetType,
        string $targetId,
        string $confirm,
        bool $immediate = false,
    ): array {
        return [
            'targetType' => $targetType,
            'targetId' => $targetId,
            'confirm' => $confirm,
            'reason' => 'GDPR erasure request #1234',
            'immediate' => $immediate,
            'notifyOwner' => false,
            'currentPassword' => self::PASSWORD,
        ];
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
