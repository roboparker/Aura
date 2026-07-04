<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The seat invariant (#billing Phase 1c): an organization Guest can only ever
 * hold the restricted guest space-role in any of the org's spaces — never a
 * space admin, never a paying seat's capabilities.
 */
class OrganizationGuestInvariantTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceRole')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testGuestAddedToSpaceIsCappedToMemberWithGuestRole(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner, 'Acme Inc');
        $guest = $this->createUser('guest@example.com');
        $org->addMember($guest, Organization::ROLE_GUEST);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($owner);

        $space = $this->makeOrgSpace($client, $org, 'Team Space');

        // Add the org guest to the space, *asking* for admin — it must be
        // downgraded to member and pinned to the built-in guest role.
        $client->request('POST', '/spaces/' . $space . '/members', [
            'json' => ['email' => 'guest@example.com', 'role' => Space::ROLE_ADMIN],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Space::class)->find($space);
        $this->assertInstanceOf(Space::class, $reloaded);
        $membership = null;
        foreach ($reloaded->getUserMemberships() as $m) {
            if ('guest@example.com' === $m->getUser()?->getEmail()) {
                $membership = $m;
            }
        }
        $this->assertNotNull($membership, 'guest should have a space membership');
        $this->assertSame(Space::ROLE_MEMBER, $membership->getRole(), 'guest must not be a space admin');

        $builtinKeys = [];
        foreach ($membership->getRoles() as $role) {
            $builtinKeys[] = $role->getBuiltinKey();
        }
        $this->assertContains(SpaceRole::BUILTIN_GUEST, $builtinKeys, 'guest must hold the guest space-role');
    }

    public function testGuestCannotBePromotedToSpaceAdmin(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner, 'Acme Inc');
        $guest = $this->createUser('guest@example.com');
        $org->addMember($guest, Organization::ROLE_GUEST);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($owner);
        $space = $this->makeOrgSpace($client, $org, 'Team Space');

        $client->request('POST', '/spaces/' . $space . '/members', [
            'json' => ['email' => 'guest@example.com', 'role' => Space::ROLE_MEMBER],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Space::class)->find($space);
        $this->assertInstanceOf(Space::class, $reloaded);
        $membershipId = null;
        foreach ($reloaded->getUserMemberships() as $m) {
            if ('guest@example.com' === $m->getUser()?->getEmail()) {
                $membershipId = (string) $m->getId();
            }
        }
        $this->assertNotNull($membershipId);

        $client->request('PATCH', '/spaces/' . $space . '/members/' . $membershipId, [
            'json' => ['role' => Space::ROLE_ADMIN],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    private function makeOrgSpace(Client $client, Organization $org, string $name): string
    {
        $body = $client->request('POST', '/spaces', [
            'json' => ['name' => $name, 'organization' => '/organizations/' . $org->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $id = $body['id'] ?? null;
        $this->assertIsString($id);

        return $id;
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
