<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Edge/error branches of {@see \App\Controller\UserGroupMemberController}
 * not covered by UserGroupTest: the add-by-email invite branch for an
 * unknown address, add-already-member 409, the missing-email 400,
 * removing a user who isn't a member (404), and the non-member-leave
 * existence-hiding 404.
 */
class UserGroupMemberEdgeTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\GroupInvite')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserInvite')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserGroup')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAddUnknownEmailCreatesInvite(): void
    {
        $alice = $this->createUser('alice@example.com');
        $group = $this->createGroup($alice, 'Solo', [$alice]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $body = $this->body($client);
        $this->assertSame('invited', $this->stringField($body, 'status'));
        $this->assertSame('newcomer@example.com', $this->stringField($body, 'email'));
        $this->assertArrayHasKey('inviteId', $body);
        $this->assertArrayHasKey('expiresAt', $body);
    }

    public function testAddAlreadyMemberReturns409(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $group = $this->createGroup($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'bob@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testAddMemberWithMissingEmailReturns400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $group = $this->createGroup($alice, 'Solo', [$alice]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => '  '],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAddMemberWithInvalidEmailReturns422(): void
    {
        $alice = $this->createUser('alice@example.com');
        $group = $this->createGroup($alice, 'Solo', [$alice]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'not-an-email'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRemoveUserWhoIsNotAMemberReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $outsider = $this->createUser('outsider@example.com');
        $group = $this->createGroup($alice, 'Solo', [$alice]);

        $client = static::createClient();
        $client->loginUser($alice);
        // A real user that isn't part of the group.
        $client->request('DELETE', '/groups/' . $group->getId() . '/members/' . $outsider->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonMemberLeaveReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $outsider = $this->createUser('outsider@example.com');
        $group = $this->createGroup($alice, 'Solo', [$alice]);

        $client = static::createClient();
        $client->loginUser($outsider);
        // Existence-hiding: a non-member probing /leave gets a 404.
        $client->request('POST', '/groups/' . $group->getId() . '/leave');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function body(Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        return $response->toArray(false);
    }

    private function createUser(string $email): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

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

    /**
     * Creates a group in a fresh space owned (admin) by $owner — so $owner
     * (the actor in these tests) is a member of the group's space and may
     * manage it.
     *
     * @param User[] $members
     */
    private function createGroup(User $owner, string $title, array $members): UserGroup
    {
        $space = new Space();
        $space->setName($title . ' space');
        $space->setCreatedBy($owner);
        $this->entityManager->persist($space);
        $admin = (new SpaceMembership())
            ->setUser($owner)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($admin);
        $this->entityManager->persist($admin);

        $group = new UserGroup();
        $group->setSpace($space);
        $group->setTitle($title);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
        $group->setSlug('' === $slug ? 'group' : $slug);
        foreach ($members as $member) {
            $group->addMember($member);
        }

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
    }
}
