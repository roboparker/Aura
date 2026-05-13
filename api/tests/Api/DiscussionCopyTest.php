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
 * `POST /discussions/{id}/copy` — clones a discussion into a target
 * space.
 */
class DiscussionCopyTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\Discussion')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRequiresAuth(): void
    {
        static::createClient()->request('POST', '/discussions/' . str_repeat('0', 36) . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCopiesIntoSourceSpaceByDefault(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $discussion = $this->seedDiscussion($alice, $source, 'Welcome', 'Quick intro.', 'announcements');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $client->getResponse()->toArray();
        $this->assertSame('Welcome (copy)', $body['title']);
        $this->assertSame('/spaces/' . $source->getId(), $body['space']);

        $this->entityManager->clear();
        $copy = $this->entityManager->getRepository(Discussion::class)->find($body['id']);
        $this->assertNotNull($copy);
        $this->assertSame('Quick intro.', $copy->getBody());
        $this->assertSame('announcements', $copy->getCategory());
        $this->assertSame((string) $alice->getId(), (string) $copy->getAuthor()->getId());
        $this->assertFalse($copy->getIsPinned());
        $this->assertFalse($copy->getIsLocked());
    }

    public function testCopiesIntoExplicitTargetSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $discussion = $this->seedDiscussion($alice, $source, 'Idea', 'Body');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $client->getResponse()->toArray();
        $this->assertSame('/spaces/' . $target->getId(), $body['space']);
    }

    public function testCopyDoesNotDoubleSuffix(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $discussion = $this->seedDiscussion($alice, $source, 'Notes (copy)');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Notes (copy)', $client->getResponse()->toArray()['title']);
    }

    public function testCopyAuthorIsCurrentUserNotSourceAuthor(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $source = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($source, $bob);
        $discussion = $this->seedDiscussion($alice, $source, 'Aliceʼs thread');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $copy = $this->entityManager->getRepository(Discussion::class)
            ->find($client->getResponse()->toArray()['id']);
        $this->assertSame((string) $bob->getId(), (string) $copy->getAuthor()->getId());
    }

    public function testPinAndLockResetOnCopy(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $discussion = $this->seedDiscussion($alice, $source, 'Sticky thread');
        $discussion->setIsPinned(true);
        $discussion->setIsLocked(true);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $copy = $this->entityManager->getRepository(Discussion::class)
            ->find($client->getResponse()->toArray()['id']);
        $this->assertFalse($copy->getIsPinned());
        $this->assertFalse($copy->getIsLocked());

        $reloaded = $this->entityManager->getRepository(Discussion::class)->find($discussion->getId());
        $this->assertTrue($reloaded->getIsPinned());
        $this->assertTrue($reloaded->getIsLocked());
    }

    public function testNonMemberOfSourceGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $source = $this->createSpace($alice, 'Source');
        $discussion = $this->seedDiscussion($alice, $source, 'Hidden');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => [],
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
        $discussion = $this->seedDiscussion($alice, $source, 'D');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidSpaceIriGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $discussion = $this->seedDiscussion($alice, $source, 'D');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => ['space' => 'not-a-real-iri'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testAcceptsBareUuidAsTarget(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $discussion = $this->seedDiscussion($alice, $source, 'D');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => ['space' => (string) $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('/spaces/' . $target->getId(), $client->getResponse()->toArray()['space']);
    }

    private function seedDiscussion(
        User $author,
        Space $space,
        string $title,
        string $body = 'Body',
        string $category = 'general',
    ): Discussion {
        $disc = new Discussion();
        $disc->setSpace($space);
        $disc->setAuthor($author);
        $disc->setTitle($title);
        $disc->setBody($body);
        $disc->setCategory($category);
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
