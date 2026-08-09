<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Deletion\PurgeRunner;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase 2 step 1: every user has a personal organization, and every space they
 * create belongs to one.
 *
 * The invariant these pin is "no space exists without an account behind it".
 * The column is still nullable at this step — the NOT NULL constraint lands
 * later — so the tests assert the *behaviour* that makes that constraint safe
 * to add rather than relying on the database to catch it.
 */
class PersonalOrganizationTest extends ApiTestCase
{
    private const PASSWORD = 'Password123!@#';

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

    public function testSignupProvisionsAPersonalOrganizationThatOwnsThePersonalSpace(): void
    {
        $client = static::createClient();
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'newbie@example.com',
                'plainPassword' => self::PASSWORD,
                'givenName' => 'New',
                'familyName' => 'Bie',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $em = $this->em();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'newbie@example.com']);
        $this->assertNotNull($user);

        $org = $em->getRepository(Organization::class)
            ->findOneBy(['createdBy' => $user, 'isPersonal' => true]);
        $this->assertNotNull($org, 'signup should provision a personal organization');
        $this->assertTrue($org->isOwner($user), 'the user owns their own account');
        $this->assertStringStartsWith('o-', $org->getSlug());

        $personalSpace = $em->getRepository(Space::class)
            ->findOneBy(['createdBy' => $user, 'isPersonal' => true]);
        $this->assertNotNull($personalSpace);
        $this->assertSame(
            (string) $org->getId(),
            (string) $personalSpace->getOrganization()?->getId(),
            'the personal space must belong to the personal organization',
        );
    }

    public function testSpaceCreatedWithoutAnOrganizationLandsInTheCreatorsOwn(): void
    {
        $user = $this->createUser('solo@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $body = $client->request('POST', '/spaces', [
            'json' => ['name' => 'Side Project'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);

        // The branch this phase exists to delete: a space with no account
        // behind it. It should no longer be reachable.
        $this->assertNotNull($body['organization'] ?? null, 'every space must have an organization');

        $em = $this->em();
        $em->clear();
        $reloaded = $em->getRepository(Space::class)->findOneBy(['name' => 'Side Project']);
        $this->assertNotNull($reloaded);
        $org = $reloaded->getOrganization();
        $this->assertNotNull($org);
        $this->assertTrue($org->getIsPersonal());
    }

    public function testTheSamePersonalOrganizationIsReusedAcrossSpaces(): void
    {
        $user = $this->createUser('solo@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        foreach (['One', 'Two'] as $name) {
            $client->request('POST', '/spaces', [
                'json' => ['name' => $name],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ]);
            $this->assertResponseStatusCodeSame(201);
        }

        $em = $this->em();
        $em->clear();
        $reloadedUser = $em->getRepository(User::class)->findOneBy(['email' => 'solo@example.com']);
        $this->assertNotNull($reloadedUser);
        $personal = $em->getRepository(Organization::class)
            ->findBy(['createdBy' => $reloadedUser, 'isPersonal' => true]);

        // The partial unique index enforces this at the DB, but a second one
        // would mean two places a plan could live, so it's worth asserting.
        $this->assertCount(1, $personal, 'a user has exactly one personal organization');
    }

    public function testPersonalOrganizationCannotBeDeletedOnItsOwn(): void
    {
        $user = $this->createUser('solo@example.com');
        $client = static::createClient();
        $client->loginUser($user);
        // Provision it by creating a space.
        $client->request('POST', '/spaces', [
            'json' => ['name' => 'Anything'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $em = $this->em();
        $reloadedUser = $em->getRepository(User::class)->findOneBy(['email' => 'solo@example.com']);
        $this->assertNotNull($reloadedUser);
        $org = $em->getRepository(Organization::class)
            ->findOneBy(['createdBy' => $reloadedUser, 'isPersonal' => true]);
        $this->assertNotNull($org);

        $client->request('POST', '/organizations/' . $org->getId() . '/delete', [
            'json' => [
                'confirmName' => $org->getName(),
                'reason' => 'not_using',
                'currentPassword' => self::PASSWORD,
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        // It goes with the account, not separately — deleting it alone would
        // strand the personal space.
        $this->assertResponseStatusCodeSame(409);
    }

    public function testAccountDeletionTakesThePersonalOrganization(): void
    {
        $user = $this->createUser('leaver@example.com');
        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/spaces', [
            'json' => ['name' => 'Goes Away'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $client->request('POST', '/me/delete', [
            'json' => [
                'confirmEmail' => 'leaver@example.com',
                'currentPassword' => self::PASSWORD,
                'reason' => 'not_using',
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $purge = static::getContainer()->get(PurgeRunner::class);
        $purge->run(new \DateTimeImmutable('+31 days'));

        $em = $this->em();
        $em->clear();
        $this->assertNull($em->getRepository(User::class)->findOneBy(['email' => 'leaver@example.com']));
        $this->assertCount(
            0,
            $em->getRepository(Organization::class)->findBy(['isPersonal' => true]),
            'a personal organization must not outlive its owner',
        );
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);

        return $em;
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
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
