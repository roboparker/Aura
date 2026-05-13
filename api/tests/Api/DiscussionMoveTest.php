<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Discussion;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /discussions/{id}/move` — re-points a discussion at a
 * different space.
 */
class DiscussionMoveTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get(EntityManagerInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Discussion')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRequiresAuth(): void
    {
        static::createClient()->request('POST', '/discussions/' . str_repeat('0', 36) . '/move', [
            'json' => ['space' => '/spaces/x'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMemberOfBothSpacesCanMoveDiscussion(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $discussion = $this->seedDiscussion($alice, $source, 'Welcome');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue($client->getResponse()->toArray()['moved']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Discussion::class)->find($discussion->getId());
        $this->assertSame((string) $target->getId(), (string) $reloaded->getSpace()->getId());
    }

    public function testNonMemberOfSourceGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($stranger, 'Target');
        $discussion = $this->seedDiscussion($alice, $source, 'Hidden');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonMemberOfTargetGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $source = $this->createSpace($alice, 'Source');
        $this->ensureSpaceMembership($source, $bob);
        $target = $this->createSpace($alice, 'Target');
        // Bob is in source but not target.
        $discussion = $this->seedDiscussion($alice, $source, 'Move me?');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testMissingSpacePayloadGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $discussion = $this->seedDiscussion($alice, $source, 'D');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/move', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testInvalidSpaceIriGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $discussion = $this->seedDiscussion($alice, $source, 'D');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/move', [
            'json' => ['space' => 'not-an-iri-or-uuid'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveToCurrentSpaceIsNoOp(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $discussion = $this->seedDiscussion($alice, $source, 'D');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $source->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($client->getResponse()->toArray()['moved']);
    }

    public function testAcceptsBareUuidAsTarget(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $discussion = $this->seedDiscussion($alice, $source, 'D');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/move', [
            'json' => ['space' => (string) $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue($client->getResponse()->toArray()['moved']);
    }

    private function seedDiscussion(User $author, Space $space, string $title): Discussion
    {
        $disc = new Discussion();
        $disc->setSpace($space);
        $disc->setAuthor($author);
        $disc->setTitle($title);
        $disc->setBody('Body');
        $disc->setCategory('general');
        $this->entityManager->persist($disc);
        $this->entityManager->flush();
        return $disc;
    }

    private function createSpace(User $owner, string $name): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($owner);
        $this->entityManager->persist($space);

        $admin = (new SpaceMembership())
            ->setUser($owner)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($admin);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        return $space;
    }

    /**
     * @param string[] $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
