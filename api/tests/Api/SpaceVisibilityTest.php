<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceInvite;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Entity\UserInvite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coverage for the private/shared visibility model on Space.
 *
 * Boundary checks:
 *   - personal spaces default to (and stay) private
 *   - non-personal spaces default to shared, can be created private
 *   - only the creator can flip visibility
 *   - shared → private kicks every other member and revokes pending invites
 *   - private → shared is a side-effect-free unlock
 *   - personal space rejects any visibility flip (422)
 *   - add-member endpoint rejects private targets (409)
 */
class SpaceVisibilityTest extends ApiTestCase
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

    public function testSignupPersonalSpaceIsPrivate(): void
    {
        $client = static::createClient();
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'alice@example.com',
                'plainPassword' => 'Password123!@#',
                'givenName' => 'Alice',
                'familyName' => 'Doe',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $alice = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'alice@example.com']);
        $this->assertNotNull($alice);
        $personal = $this->entityManager
            ->getRepository(Space::class)
            ->findOneBy(['createdBy' => $alice, 'isPersonal' => true]);
        $this->assertNotNull($personal);
        $this->assertSame(Space::VISIBILITY_PRIVATE, $personal->getVisibility());
    }

    public function testCreatedSpaceDefaultsToShared(): void
    {
        $alice = $this->createUserWithSpace('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces', [
            'json' => ['name' => 'Team A'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            'name' => 'Team A',
            'visibility' => Space::VISIBILITY_SHARED,
        ]);
    }

    public function testCanCreateSpaceAsPrivate(): void
    {
        $alice = $this->createUserWithSpace('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces', [
            'json' => [
                'name' => 'Solo bucket',
                'visibility' => Space::VISIBILITY_PRIVATE,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            'name' => 'Solo bucket',
            'visibility' => Space::VISIBILITY_PRIVATE,
        ]);
    }

    public function testFlippingPersonalSpaceToSharedIs422(): void
    {
        $alice = $this->createUserWithSpace('alice@example.com');
        $personal = $this->entityManager
            ->getRepository(Space::class)
            ->findOneBy(['createdBy' => $alice, 'isPersonal' => true]);
        $this->assertNotNull($personal);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/spaces/' . $personal->getId(), [
            'json' => ['visibility' => Space::VISIBILITY_SHARED],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testFlippingSharedToPrivateKicksMembersAndRevokesInvites(): void
    {
        $alice = $this->createUserWithSpace('alice@example.com');
        $bob = $this->createUserWithSpace('bob@example.com');
        $space = $this->getSharedSpace($alice);
        $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);
        $this->seedInvite($alice, $space, 'pending@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/spaces/' . $space->getId(), [
            'json' => ['visibility' => Space::VISIBILITY_PRIVATE],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['visibility' => Space::VISIBILITY_PRIVATE]);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Space::class)->find($space->getId());
        $this->assertNotNull($reloaded);
        // Only the creator's membership survives.
        $this->assertCount(1, $reloaded->getUserMemberships());
        $survivor = $reloaded->getUserMemberships()->first();
        self::assertNotFalse($survivor);
        $this->assertSame('alice@example.com', $survivor->getUser()?->getEmail());

        // Pending SpaceInvite + parent UserInvite are both gone.
        $invite = $this->entityManager
            ->getRepository(UserInvite::class)
            ->findOneBy(['email' => 'pending@example.com']);
        $this->assertNull($invite);
        $remainingSpaceInvites = $this->entityManager
            ->getRepository(SpaceInvite::class)
            ->findBy(['space' => $reloaded]);
        $this->assertSame([], $remainingSpaceInvites);
    }

    public function testNonCreatorAdminCannotFlipVisibility(): void
    {
        $alice = $this->createUserWithSpace('alice@example.com');
        $bob = $this->createUserWithSpace('bob@example.com');
        $space = $this->getSharedSpace($alice);
        // Promote Bob to admin so the standard Patch security expression
        // would normally pass; visibility is the field-level gate.
        $this->ensureSpaceMembership($space, $bob, Space::ROLE_ADMIN);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/spaces/' . $space->getId(), [
            'json' => ['visibility' => Space::VISIBILITY_PRIVATE],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testFlippingPrivateBackToSharedHasNoSideEffects(): void
    {
        $alice = $this->createUserWithSpace('alice@example.com');
        $space = (new Space())
            ->setName('Solo')
            ->setVisibility(Space::VISIBILITY_PRIVATE)
            ->setCreatedBy($alice);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->ensureSpaceMembership($space, $alice, Space::ROLE_ADMIN);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/spaces/' . $space->getId(), [
            'json' => ['visibility' => Space::VISIBILITY_SHARED],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['visibility' => Space::VISIBILITY_SHARED]);
    }

    public function testAddMemberToPrivateSpaceIs409(): void
    {
        $alice = $this->createUserWithSpace('alice@example.com');
        $bob = $this->createUserWithSpace('bob@example.com');
        $space = (new Space())
            ->setName('Solo')
            ->setVisibility(Space::VISIBILITY_PRIVATE)
            ->setCreatedBy($alice);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->ensureSpaceMembership($space, $alice, Space::ROLE_ADMIN);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/spaces/' . $space->getId() . '/members', [
            'json' => ['email' => 'bob@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(409);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Space::class)->find($space->getId());
        $this->assertNotNull($reloaded);
        $this->assertFalse($reloaded->hasMember($bob));
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
            ->setVisibility(Space::VISIBILITY_PRIVATE)
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
        $space = (new Space())
            ->setName($name)
            ->setVisibility(Space::VISIBILITY_SHARED)
            ->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->ensureSpaceMembership($space, $admin, Space::ROLE_ADMIN);
        return $space;
    }

    private function seedInvite(User $invitedBy, Space $space, string $email): UserInvite
    {
        $plainToken = bin2hex(random_bytes(32));
        $invite = new UserInvite(
            $email,
            hash('sha256', $plainToken),
            new \DateTimeImmutable('+14 days'),
        );
        $this->entityManager->persist($invite);
        new SpaceInvite($invite, $space, $invitedBy);
        $this->entityManager->flush();
        return $invite;
    }
}
