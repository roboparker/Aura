<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Organization CRUD + membership endpoints (#billing Phase 1a).
 */
class OrganizationApiTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\OrganizationMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Organization')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCreateMakesCreatorOwner(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('owner@example.com'));
        $body = $client->request('POST', '/organizations', [
            'json' => ['name' => 'Acme Inc'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Acme Inc', $body['name']);
        $slug = $body['slug'] ?? null;
        $this->assertIsString($slug);
        $this->assertStringStartsWith('o-', $slug);
        $this->assertSame(1, $body['seatCount']);
        $members = $body['memberList'];
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $first = $members[0];
        $this->assertIsArray($first);
        $this->assertSame('owner', $first['role']);
    }

    public function testListScopedToMemberOrgs(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->makeOrg($alice, 'Alice Org');
        $bob = $this->createUser('bob@example.com');

        $client = static::createClient();
        $client->loginUser($bob);
        $body = $client->request('GET', '/organizations')->toArray();

        // Bob belongs to no org → sees none of Alice's.
        $this->assertSame(0, $body['totalItems'] ?? -1);
    }

    public function testGetNonMemberIsNotFound(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice, 'Alice Org');
        $bob = $this->createUser('bob@example.com');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/organizations/' . $org->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAddChangeRoleAndRemoveMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice, 'Alice Org');
        $bob = $this->createUser('bob@example.com');

        $client = static::createClient();
        $client->loginUser($alice);

        // Add Bob as a member.
        $client->request('POST', '/organizations/' . $org->getId() . '/members', [
            'json' => ['email' => 'bob@example.com', 'role' => 'member'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Promote Bob to admin.
        $client->request('PATCH', '/organizations/' . $org->getId() . '/members/' . $bob->getId(), [
            'json' => ['role' => 'admin'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Remove Bob.
        $client->request('DELETE', '/organizations/' . $org->getId() . '/members/' . $bob->getId());
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Organization::class)->find($org->getId());
        $this->assertInstanceOf(Organization::class, $reloaded);
        $this->assertSame(1, $reloaded->seatCount());
    }

    public function testCannotRemoveLastOwner(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice, 'Alice Org');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/organizations/' . $org->getId() . '/members/' . $alice->getId());
        $this->assertResponseStatusCodeSame(409);
    }

    public function testNonAdminMemberCannotManageMembers(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice, 'Alice Org');
        $bob = $this->createUser('bob@example.com');
        $org->addMember($bob, Organization::ROLE_MEMBER);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/organizations/' . $org->getId() . '/members', [
            'json' => ['email' => 'someone@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateSpaceUnderOrganization(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice, 'Alice Org');

        $client = static::createClient();
        $client->loginUser($alice);
        $body = $client->request('POST', '/spaces', [
            'json' => ['name' => 'Team Space', 'organization' => '/organizations/' . $org->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $this->assertResponseStatusCodeSame(201);
        $organizationIri = $body['organization'] ?? null;
        $this->assertIsString($organizationIri);
        $this->assertStringContainsString('/organizations/' . $org->getId(), $organizationIri);
    }

    public function testGuestCannotCreateSpaceUnderOrganization(): void
    {
        $alice = $this->createUser('alice@example.com');
        $org = $this->makeOrg($alice, 'Alice Org');
        $guest = $this->createUser('guest@example.com');
        $org->addMember($guest, Organization::ROLE_GUEST);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($guest);
        $client->request('POST', '/spaces', [
            'json' => ['name' => 'Sneaky', 'organization' => '/organizations/' . $org->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    private function makeOrg(User $owner, string $name): Organization
    {
        $org = (new Organization())->setName($name)->setSlug('o-' . bin2hex(random_bytes(4)))->setCreatedBy($owner);
        $org->addMember($owner, Organization::ROLE_OWNER);
        $this->entityManager->persist($org);
        $this->entityManager->flush();

        return $org;
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
