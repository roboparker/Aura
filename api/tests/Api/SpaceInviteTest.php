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
 * Coverage for the space invite flow (#186): admin-only add-by-email
 * with the existing-user / unknown-email branch, list + revoke, the
 * public lookup payload, and the signup hand-off that turns pending
 * SpaceInvites into SpaceMembership rows.
 */
class SpaceInviteTest extends ApiTestCase
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

    public function testNonMemberSeesSpaceAsNotFound(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $stranger = $this->createUserWithSpace('stranger@example.com');
        $space = $this->getSharedSpace($admin);

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/spaces/' . $space->getId() . '/members', [
            'json' => ['email' => 'someone@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        // Existence-hidden 404, not 403.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonAdminMemberCannotAddOrInvite(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $regular = $this->createUserWithSpace('regular@example.com');
        $space = $this->getSharedSpace($admin);
        $this->ensureSpaceMembership($space, $regular, Space::ROLE_MEMBER);

        $client = static::createClient();
        $client->loginUser($regular);
        $client->request('POST', '/spaces/' . $space->getId() . '/members', [
            'json' => ['email' => 'someone@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminAddsExistingUserDirectly(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $bob = $this->createUserWithSpace('bob@example.com');
        $space = $this->getSharedSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces/' . $space->getId() . '/members', [
            'json' => ['email' => 'bob@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains([
            'status' => 'added',
            'email' => 'bob@example.com',
        ]);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Space::class)->find($space->getId());
        $this->assertTrue($reloaded->hasMember($bob));
    }

    public function testAdminAddingExistingMemberAgainConflicts(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $bob = $this->createUserWithSpace('bob@example.com');
        $space = $this->getSharedSpace($admin);
        $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces/' . $space->getId() . '/members', [
            'json' => ['email' => 'bob@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testAdminInvitesUnknownEmailCreatingInvite(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $space = $this->getSharedSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces/' . $space->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains([
            'status' => 'invited',
            'email' => 'newcomer@example.com',
        ]);

        $this->entityManager->clear();
        $invite = $this->entityManager->getRepository(UserInvite::class)
            ->findOneBy(['email' => 'newcomer@example.com']);
        $this->assertNotNull($invite, 'A UserInvite row should have been created.');
        $this->assertCount(1, $invite->getSpaceInvites());
        $this->assertSame(
            (string) $space->getId(),
            (string) $invite->getSpaceInvites()->first()->getSpace()->getId(),
        );
        $this->assertNull($invite->getAcceptedAt());
    }

    public function testInvitingSameEmailTwiceIsIdempotent(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $space = $this->getSharedSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/spaces/' . $space->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $client->request('POST', '/spaces/' . $space->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $invite = $this->entityManager->getRepository(UserInvite::class)
            ->findOneBy(['email' => 'newcomer@example.com']);
        $this->assertNotNull($invite);
        // Token rotated but only one SpaceInvite row attached.
        $this->assertCount(1, $invite->getSpaceInvites());
    }

    public function testListInvitesAdminOnly(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $regular = $this->createUserWithSpace('regular@example.com');
        $space = $this->getSharedSpace($admin);
        $this->ensureSpaceMembership($space, $regular, Space::ROLE_MEMBER);
        $this->seedInvite($admin, $space, 'pending@example.com');

        $client = static::createClient();
        $client->loginUser($regular);
        $client->request('GET', '/spaces/' . $space->getId() . '/invites');
        $this->assertResponseStatusCodeSame(403);

        $client->loginUser($admin);
        $client->request('GET', '/spaces/' . $space->getId() . '/invites');
        $this->assertResponseIsSuccessful();
        $payload = $client->getResponse()->toArray();
        $this->assertCount(1, $payload['invites']);
        $this->assertSame('pending@example.com', $payload['invites'][0]['email']);
    }

    public function testRevokeInviteDropsParentWhenLastAttachment(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $space = $this->getSharedSpace($admin);
        $invite = $this->seedInvite($admin, $space, 'pending@example.com');
        $spaceInvite = $invite->getSpaceInvites()->first();

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request(
            'DELETE',
            '/spaces/' . $space->getId() . '/invites/' . $spaceInvite->getId(),
        );
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $remaining = $this->entityManager->getRepository(UserInvite::class)
            ->findOneBy(['email' => 'pending@example.com']);
        $this->assertNull(
            $remaining,
            'UserInvite should be removed when the last attachment is revoked.',
        );
    }

    public function testInviteTokenLookupExposesSpaces(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $space = $this->getSharedSpace($admin);
        [$invite, $plainToken] = $this->seedInviteWithToken($admin, $space, 'pending@example.com');

        $client = static::createClient();
        $client->request('GET', '/invites/' . $plainToken);
        $this->assertResponseIsSuccessful();
        $payload = $client->getResponse()->toArray();
        $this->assertSame('pending@example.com', $payload['email']);
        $this->assertCount(1, $payload['spaces']);
        $this->assertSame((string) $space->getId(), $payload['spaces'][0]['id']);
        $this->assertSame($space->getName(), $payload['spaces'][0]['name']);
    }

    public function testSignupWithInviteTokenAttachesUserToSpace(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $space = $this->getSharedSpace($admin);
        [$invite, $plainToken] = $this->seedInviteWithToken($admin, $space, 'newcomer@example.com');

        $client = static::createClient();
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'newcomer@example.com',
                'plainPassword' => 'password123',
                'givenName' => 'Newt',
                'familyName' => 'Newcomer',
                'inviteToken' => $plainToken,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $newcomer = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'newcomer@example.com']);
        $this->assertNotNull($newcomer);
        $reloadedSpace = $this->entityManager->getRepository(Space::class)->find($space->getId());
        $this->assertTrue(
            $reloadedSpace->hasMember($newcomer),
            'Signup with the invite token should attach the new user to the space.',
        );

        $reloadedInvite = $this->entityManager->getRepository(UserInvite::class)->find($invite->getId());
        $this->assertNotNull($reloadedInvite?->getAcceptedAt());
    }

    public function testSignupWithMismatchedEmailDoesNotAttach(): void
    {
        $admin = $this->createUserWithSpace('admin@example.com');
        $space = $this->getSharedSpace($admin);
        [, $plainToken] = $this->seedInviteWithToken($admin, $space, 'newcomer@example.com');

        $client = static::createClient();
        // Signup email differs from the invite email — token alone shouldn't redirect access.
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'imposter@example.com',
                'plainPassword' => 'password123',
                'givenName' => 'Im',
                'familyName' => 'Poster',
                'inviteToken' => $plainToken,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $imposter = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'imposter@example.com']);
        $reloadedSpace = $this->entityManager->getRepository(Space::class)->find($space->getId());
        $this->assertFalse($reloadedSpace->hasMember($imposter));
    }

    /**
     * Seeds a user via direct EM persistence + a personal space and a
     * dedicated shared space owned by them. Keeps tests terse without
     * routing through the API for setup.
     */
    private function createUserWithSpace(string $email): User
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

        // Personal space + admin role so the access predicates have
        // somewhere meaningful to point.
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
        // Cached per-test under the admin's email so the same admin
        // gets a stable shared space across helper calls.
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

    private function seedInvite(User $invitedBy, Space $space, string $email): UserInvite
    {
        [$invite] = $this->seedInviteWithToken($invitedBy, $space, $email);
        return $invite;
    }

    /**
     * @return array{0: UserInvite, 1: string} the invite and its
     *   plaintext token (the same token the email would have carried)
     */
    private function seedInviteWithToken(User $invitedBy, Space $space, string $email): array
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
        return [$invite, $plainToken];
    }
}
