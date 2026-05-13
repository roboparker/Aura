<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SpaceTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Wipe in dependency order so FK cascades fire cleanly. Spaces
        // cascade their memberships; users cascade everything else.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListSpacesUnauthenticated(): void
    {
        static::createClient()->request('GET', '/spaces');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testSignupProvisionsPersonalSpace(): void
    {
        $client = static::createClient();
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'alice@example.com',
                'plainPassword' => 'password123',
                'givenName' => 'Alice',
                'familyName' => 'Doe',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);

        $alice = $this->reloadUser('alice@example.com');
        $personal = $this->entityManager
            ->getRepository(Space::class)
            ->findOneBy(['createdBy' => $alice, 'isPersonal' => true]);
        $this->assertNotNull($personal, 'Signup should provision a personal space.');
        $this->assertSame(Space::PERSONAL_SPACE_NAME, $personal->getName());
        $this->assertTrue($personal->getIsPersonal());
        // Creator is admin; no other memberships.
        $this->assertCount(1, $personal->getUserMemberships());
        $membership = $personal->getUserMemberships()->first();
        self::assertNotFalse($membership);
        $this->assertTrue($alice->getId()?->equals($membership->getUser()?->getId()));
        $this->assertSame(Space::ROLE_ADMIN, $membership->getRole());
    }

    public function testListShowsOnlySpacesUserBelongsTo(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');
        $bob = $this->createUserWithPersonalSpace('bob@example.com');
        // Shared space owned by alice, bob NOT a member.
        $this->createSharedSpace($alice, 'Alice solo shared');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/spaces');

        $this->assertResponseIsSuccessful();
        // Bob sees only his personal space.
        $this->assertJsonContains(['totalItems' => 1]);
    }

    public function testGetOtherUsersPersonalSpaceReturns404(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');
        $bob = $this->createUserWithPersonalSpace('bob@example.com');

        $alicePersonal = $this->entityManager
            ->getRepository(Space::class)
            ->findOneBy(['createdBy' => $alice, 'isPersonal' => true]);
        $this->assertNotNull($alicePersonal);

        $client = static::createClient();
        $client->loginUser($bob);
        // Existence-hiding 404, mirroring the project/group pattern.
        $client->request('GET', '/spaces/' . $alicePersonal->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateSharedSpaceMakesCreatorAdmin(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces', [
            'json' => [
                'name' => 'Backend team',
                'description' => 'PHP folks',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Space',
            'name' => 'Backend team',
            'isPersonal' => false,
        ]);

        $this->entityManager->clear();
        $space = $this->entityManager
            ->getRepository(Space::class)
            ->findOneBy(['name' => 'Backend team']);
        $this->assertNotNull($space);
        $this->assertFalse($space->getIsPersonal());
        $this->assertTrue($alice->getId()?->equals($space->getCreatedBy()?->getId()));
        $this->assertCount(1, $space->getUserMemberships());
        $firstMembership = $space->getUserMemberships()->first();
        self::assertNotFalse($firstMembership);
        $this->assertSame(Space::ROLE_ADMIN, $firstMembership->getRole());
    }

    public function testIsPersonalIgnoredOnCreate(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        // Client tries to mark its new space personal — system must reject the flag.
        $client->request('POST', '/spaces', [
            'json' => [
                'name' => 'Sneaky',
                'isPersonal' => true,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains(['name' => 'Sneaky', 'isPersonal' => false]);
    }

    public function testCreateSpaceRequiresName(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces', [
            'json' => ['name' => ''],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testNonAdminMemberCannotPatch(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');
        $bob = $this->createUserWithPersonalSpace('bob@example.com');
        $shared = $this->createSharedSpace($alice, 'Shared');
        $this->addMember($shared, $bob, Space::ROLE_MEMBER);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/spaces/' . $shared->getId(), [
            'json' => ['description' => 'Bob trying to edit'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanPatch(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');
        $shared = $this->createSharedSpace($alice, 'Shared');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/spaces/' . $shared->getId(), [
            'json' => ['description' => 'Updated'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testPersonalSpaceCannotBeDeleted(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');
        $personal = $this->entityManager
            ->getRepository(Space::class)
            ->findOneBy(['createdBy' => $alice, 'isPersonal' => true]);
        $this->assertNotNull($personal);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/spaces/' . $personal->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNonAdminMemberCannotDelete(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');
        $bob = $this->createUserWithPersonalSpace('bob@example.com');
        $shared = $this->createSharedSpace($alice, 'Shared');
        $this->addMember($shared, $bob, Space::ROLE_MEMBER);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('DELETE', '/spaces/' . $shared->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanDeleteSharedSpace(): void
    {
        $alice = $this->createUserWithPersonalSpace('alice@example.com');
        $shared = $this->createSharedSpace($alice, 'Shared');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/spaces/' . $shared->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    /**
     * Creates a User the way ApiPlatform's POST does — including the
     * personal-space provisioning that UserPasswordHasherProcessor adds
     * — so tests start from a realistic state without each one having
     * to round-trip through HTTP.
     */
    private function createUserWithPersonalSpace(string $email): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setEmail($email)
            ->setRoles(['ROLE_USER'])
            ->setGivenName('Test')
            ->setFamilyName('User')
            ->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $space = (new Space())
            ->setName(Space::PERSONAL_SPACE_NAME)
            ->setIsPersonal(true)
            ->setCreatedBy($user);
        $space->addUserMembership((new SpaceMembership())
            ->setUser($user)
            ->setRole(Space::ROLE_ADMIN));
        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $user;
    }

    private function createSharedSpace(User $admin, string $name): Space
    {
        $space = (new Space())
            ->setName($name)
            ->setCreatedBy($admin);
        $space->addUserMembership((new SpaceMembership())
            ->setUser($admin)
            ->setRole(Space::ROLE_ADMIN));
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        return $space;
    }

    private function addMember(Space $space, User $user, string $role): void
    {
        $space->addUserMembership((new SpaceMembership())
            ->setUser($user)
            ->setRole($role));
        $this->entityManager->flush();
    }

    private function reloadUser(string $email): User
    {
        $this->entityManager->clear();
        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        return $user;
    }
}
