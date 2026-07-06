<?php

namespace App\Tests\Api;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Organization account model (#billing Phase 1a): role helpers + seat counting
 * (guests are free). Users are persisted so the UUID-based membership lookups
 * (which survive EntityManager::clear()) have real ids.
 */
class OrganizationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRolesAndSeatCounting(): void
    {
        $owner = $this->user('owner@example.com');
        $member = $this->user('member@example.com');
        $guest = $this->user('guest@example.com');

        $org = (new Organization())->setName('Acme')->setCreatedBy($owner);
        $org->addMember($owner, Organization::ROLE_OWNER);
        $org->addMember($member, Organization::ROLE_MEMBER);
        $org->addMember($guest, Organization::ROLE_GUEST);

        $this->assertTrue($org->hasMember($owner));
        $this->assertSame(Organization::ROLE_MEMBER, $org->roleFor($member));
        $this->assertNull($org->roleFor($this->user('stranger@example.com')));

        $this->assertTrue($org->isOwner($owner));
        $this->assertTrue($org->isAdmin($owner));
        $this->assertFalse($org->isAdmin($member));
        $this->assertFalse($org->isAdmin($guest));

        // Guests don't consume a seat: owner + member = 2, guest excluded.
        $this->assertSame(2, $org->seatCount());
    }

    public function testAddMemberIsIdempotentAndUpdatesRole(): void
    {
        $owner = $this->user('o@example.com');
        $member = $this->user('m@example.com');

        $org = (new Organization())->setName('Acme')->setCreatedBy($owner);
        $org->addMember($owner, Organization::ROLE_OWNER);
        $org->addMember($member, Organization::ROLE_GUEST);
        $this->assertSame(1, $org->seatCount());

        // Re-adding the same user updates the role in place (no duplicate).
        $org->addMember($member, Organization::ROLE_ADMIN);
        $this->assertCount(2, $org->getMemberships());
        $this->assertSame(Organization::ROLE_ADMIN, $org->roleFor($member));
        $this->assertTrue($org->isAdmin($member));
        $this->assertSame(2, $org->seatCount());

        $org->removeMember($member);
        $this->assertFalse($org->hasMember($member));
        $this->assertSame(1, $org->seatCount());
    }

    private function user(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
