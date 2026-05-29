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
 * Coverage for the atomic create-with-invites path on POST /spaces.
 * The processor delegates per-email handling to SpaceMemberAdder, so
 * tests focus on the integration: existing-user emails resolve to
 * direct memberships, unknown emails resolve to invites under one
 * UserInvite per address, duplicates and the creator's own address
 * are dropped, and a single bad email fails the whole create.
 */
class SpaceCreateInvitesTest extends ApiTestCase
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

    public function testCreateWithInvitesAttachesExistingUsersAsMembers(): void
    {
        $admin = $this->createUserWithPersonalSpace('admin@example.com');
        $bob = $this->createUserWithPersonalSpace('bob@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces', [
            'json' => [
                'name' => 'Launch team',
                'invites' => ['bob@example.com'],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $space = $this->entityManager->getRepository(Space::class)
            ->findOneBy(['name' => 'Launch team']);
        $this->assertNotNull($space);
        // Creator (admin) + bob.
        $this->assertCount(2, $space->getUserMemberships());
        $bobMembership = $this->findMembership($space, $bob);
        $this->assertNotNull($bobMembership);
        $this->assertSame(Space::ROLE_MEMBER, $bobMembership->getRole());
        // No invite seeded — bob was a known user.
        $invite = $this->entityManager->getRepository(UserInvite::class)
            ->findOneBy(['email' => 'bob@example.com']);
        $this->assertNull($invite);
    }

    public function testCreateWithInvitesSeedsInviteForUnknownEmail(): void
    {
        $admin = $this->createUserWithPersonalSpace('admin@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces', [
            'json' => [
                'name' => 'External crew',
                'invites' => ['outside@example.com'],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $space = $this->entityManager->getRepository(Space::class)
            ->findOneBy(['name' => 'External crew']);
        $this->assertNotNull($space);
        // Only the creator is a member; the outsider has an invite.
        $this->assertCount(1, $space->getUserMemberships());

        $invite = $this->entityManager->getRepository(UserInvite::class)
            ->findOneBy(['email' => 'outside@example.com']);
        $this->assertNotNull($invite);
        $spaceInvites = $this->entityManager->getRepository(SpaceInvite::class)
            ->findBy(['userInvite' => $invite, 'space' => $space]);
        $this->assertCount(1, $spaceInvites);
    }

    public function testInvitesDedupeAndSkipSelf(): void
    {
        $admin = $this->createUserWithPersonalSpace('admin@example.com');
        $bob = $this->createUserWithPersonalSpace('bob@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces', [
            'json' => [
                'name' => 'Dedup test',
                // Mixed casing on bob to confirm we dedupe case-insensitively,
                // plus the admin's own address which should be silently dropped.
                'invites' => [
                    'bob@example.com',
                    'BOB@example.com',
                    'admin@example.com',
                    'outside@example.com',
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $space = $this->entityManager->getRepository(Space::class)
            ->findOneBy(['name' => 'Dedup test']);
        $this->assertNotNull($space);
        // Creator + bob (deduped); admin self-skipped.
        $this->assertCount(2, $space->getUserMemberships());
        $this->assertNotNull($this->findMembership($space, $bob));
        // One invite for the unknown email.
        $invites = $this->entityManager->getRepository(UserInvite::class)->findAll();
        $this->assertCount(1, $invites);
        $this->assertSame('outside@example.com', $invites[0]->getEmail());
    }

    public function testInvalidEmailFailsTheWholeCreate(): void
    {
        $admin = $this->createUserWithPersonalSpace('admin@example.com');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces', [
            'json' => [
                'name' => 'Should not save',
                'invites' => ['valid@example.com', 'not-an-email'],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);

        $this->entityManager->clear();
        $space = $this->entityManager->getRepository(Space::class)
            ->findOneBy(['name' => 'Should not save']);
        $this->assertNull(
            $space,
            'Validation failure must reject the entire create — no space row.',
        );
    }

    private function findMembership(Space $space, User $user): ?SpaceMembership
    {
        foreach ($space->getUserMemberships() as $m) {
            if (true === $m->getUser()?->getId()?->equals($user->getId())) {
                return $m;
            }
        }
        return null;
    }

    private function createUserWithPersonalSpace(string $email): User
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
}
