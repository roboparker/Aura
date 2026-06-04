<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coverage for admin member management on a space: change a member's
 * role, remove a member, and invite with a chosen role. Guards the
 * last-admin invariant on both demote and remove.
 */
class SpaceMemberManagementTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\UserInvite')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAdminInvitesExistingUserAsAdmin(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $dave = $this->createUserWithSpace('dave@example.com');
        $space = $this->getSharedSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces/' . $space->getId() . '/members', [
            'json' => ['email' => 'dave@example.com', 'role' => 'admin'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'added', 'role' => 'admin']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Space::class)->find($space->getId());
        $this->assertNotNull($reloaded);
        $this->assertTrue($reloaded->isAdmin($dave));
    }

    public function testAdminPromotesMemberToAdmin(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $bob = $this->createUserWithSpace('bob@example.com');
        $space = $this->getSharedSpace($admin);
        $bobMembership = $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);
        $bobMid = (string) $bobMembership->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('PATCH', '/spaces/' . $space->getId() . '/members/' . $bobMid, [
            'json' => ['role' => 'admin'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'updated', 'role' => 'admin']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Space::class)->find($space->getId());
        $this->assertNotNull($reloaded);
        $this->assertTrue($reloaded->isAdmin($bob));
    }

    public function testCannotDemoteLastAdmin(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $space = $this->getSharedSpace($admin);
        $adminMembership = $this->ensureSpaceMembership($space, $admin, Space::ROLE_ADMIN);
        $adminMid = (string) $adminMembership->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('PATCH', '/spaces/' . $space->getId() . '/members/' . $adminMid, [
            'json' => ['role' => 'member'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testAdminRemovesMember(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $bob = $this->createUserWithSpace('bob@example.com');
        $space = $this->getSharedSpace($admin);
        $bobMembership = $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);
        $bobMid = (string) $bobMembership->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('DELETE', '/spaces/' . $space->getId() . '/members/' . $bobMid);

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Space::class)->find($space->getId());
        $this->assertNotNull($reloaded);
        $this->assertFalse($reloaded->hasMember($bob));
    }

    public function testCannotRemoveLastAdmin(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $space = $this->getSharedSpace($admin);
        $adminMembership = $this->ensureSpaceMembership($space, $admin, Space::ROLE_ADMIN);
        $adminMid = (string) $adminMembership->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('DELETE', '/spaces/' . $space->getId() . '/members/' . $adminMid);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testNonAdminMemberCannotManage(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $bob = $this->createUserWithSpace('bob@example.com');
        $space = $this->getSharedSpace($admin);
        $adminMembership = $this->ensureSpaceMembership($space, $admin, Space::ROLE_ADMIN);
        $adminMid = (string) $adminMembership->getId();
        $bobMembership = $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);
        $bobMid = (string) $bobMembership->getId();

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/spaces/' . $space->getId() . '/members/' . $adminMid, [
            'json' => ['role' => 'member'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/spaces/' . $space->getId() . '/members/' . $bobMid);
        $this->assertResponseStatusCodeSame(403);
    }

    private function createUserWithSpace(string $email): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setEmail($email)
            ->setRoles(['ROLE_USER'])
            ->setGivenName('Test')
            ->setFamilyName('User')
            ->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $personal = (new Space())
            ->setName(Space::PERSONAL_SPACE_NAME)
            ->setIsPersonal(true)
            ->setCreatedBy($user);
        $this->entityManager->persist($personal);
        $this->entityManager->flush();
        $this->ensureSpaceMembership($personal, $user, Space::ROLE_ADMIN);

        return $user;
    }

    private function getSharedSpace(User $admin): Space
    {
        $name = 'Shared with ' . $admin->getEmail();
        $existing = $this->entityManager->getRepository(Space::class)->findOneBy(['name' => $name]);
        if (null !== $existing) {
            return $existing;
        }
        $space = (new Space())->setName($name)->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->ensureSpaceMembership($space, $admin, Space::ROLE_ADMIN);
        return $space;
    }
}
