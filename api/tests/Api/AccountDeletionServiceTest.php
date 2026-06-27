<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Service\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the harder, irreversible branches of AccountDeletionService that the
 * API-level AccountLifecycleTest doesn't reach: sole-admin shared-space
 * promotion/archive and owned-group transfer/delete.
 */
class AccountDeletionServiceTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        // Child/join rows first — DQL DELETE bypasses cascade, so parents
        // can't go until their FK-holders are gone. UserGroup holds a space
        // FK, so it must go before Space.
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserGroupMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserGroup')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    private function service(): AccountDeletionService
    {
        return self::getContainer()->get(AccountDeletionService::class);
    }

    public function testSoleAdminSharedSpacePromotesNextMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->sharedSpace('Team', $alice);
        $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);
        $spaceId = (string) $space->getId();

        $this->service()->deleteAccount($alice);
        $this->entityManager->clear();

        $survivor = $this->entityManager->getRepository(Space::class)->find($spaceId);
        $this->assertNotNull($survivor, 'Shared space with a remaining member should survive.');
        $bobMembership = $this->entityManager->getRepository(SpaceMembership::class)
            ->findOneBy(['space' => $survivor, 'user' => $bob]);
        $this->assertNotNull($bobMembership);
        $this->assertSame(Space::ROLE_ADMIN, $bobMembership->getRole(), 'Next member should be promoted to admin.');
    }

    public function testSoleAdminEmptySharedSpaceIsRemoved(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->sharedSpace('Solo', $alice);
        $spaceId = (string) $space->getId();

        $this->service()->deleteAccount($alice);
        $this->entityManager->clear();

        $this->assertNull(
            $this->entityManager->getRepository(Space::class)->find($spaceId),
            'A shared space with no remaining members should be deleted.',
        );
    }

    public function testGroupSurvivesWhenItsSpaceSurvives(): void
    {
        // A group lives in a shared space that outlives the deleted user; the
        // group stays and the deleted user just drops out of its roster.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->sharedSpace('Team', $alice);
        $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);
        $group = $this->groupInSpace($space, 'Designers', [$alice, $bob]);
        $groupId = (string) $group->getId();

        // Delete bob — a member, not the sole admin, so the space (and group) survive.
        $this->service()->deleteAccount($bob);
        $this->entityManager->clear();

        $survivor = $this->entityManager->getRepository(UserGroup::class)->find($groupId);
        $this->assertNotNull($survivor, 'Group in a surviving space should survive.');
        $this->assertCount(1, $survivor->getMembers(), 'Deleted user should drop out of the roster.');
    }

    public function testGroupRemovedWhenItsSpaceRemoved(): void
    {
        // A group in the sole-admin's only-occupant space dies with the space.
        $alice = $this->createUser('alice@example.com');
        $space = $this->sharedSpace('Solo', $alice);
        $group = $this->groupInSpace($space, 'Lonely', [$alice]);
        $groupId = (string) $group->getId();

        $this->service()->deleteAccount($alice);
        $this->entityManager->clear();

        $this->assertNull(
            $this->entityManager->getRepository(UserGroup::class)->find($groupId),
            'A group whose space is removed should be deleted too.',
        );
    }

    private function sharedSpace(string $name, User $admin): Space
    {
        $space = (new Space())->setName($name)->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->ensureSpaceMembership($space, $admin, Space::ROLE_ADMIN);
        return $space;
    }

    /**
     * @param User[] $members
     */
    private function groupInSpace(Space $space, string $title, array $members): UserGroup
    {
        $group = (new UserGroup())
            ->setTitle($title)
            ->setSlug('g-' . strtolower($title))
            ->setSpace($space);
        foreach ($members as $member) {
            $group->addMember($member);
        }
        $this->entityManager->persist($group);
        $this->entityManager->flush();
        return $group;
    }

    /**
     * @param string[] $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
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
