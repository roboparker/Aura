<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Discussion;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /discussions/{id}/copy` — clones a discussion into a target
 * project (#182).
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

    public function testCopiesIntoSourceProjectByDefault(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $project = $this->createProject($alice, 'Backend', $source);
        $discussion = $this->seedDiscussion($alice, $project, 'Welcome', 'Quick intro.', 'announcements');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $client->getResponse()->toArray();
        $this->assertSame('Welcome (copy)', $body['title']);
        $this->assertSame('/projects/' . $project->getId(), $body['project']);
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

    public function testCopiesIntoExplicitTargetProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $sourceProject = $this->createProject($alice, 'Source', $source);
        $targetProject = $this->createProject($alice, 'Target', $target);
        $discussion = $this->seedDiscussion($alice, $sourceProject, 'Idea', 'Body');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => ['project' => '/projects/' . $targetProject->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $client->getResponse()->toArray();
        $this->assertSame('/projects/' . $targetProject->getId(), $body['project']);
        $this->assertSame('/spaces/' . $target->getId(), $body['space']);
    }

    public function testCopyDoesNotDoubleSuffix(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $project = $this->createProject($alice, 'P', $source);
        $discussion = $this->seedDiscussion($alice, $project, 'Notes (copy)');

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
        $project = $this->createProject($alice, 'P', $source);
        $discussion = $this->seedDiscussion($alice, $project, 'Aliceʼs thread');

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
        $project = $this->createProject($alice, 'P', $source);
        $discussion = $this->seedDiscussion($alice, $project, 'Sticky thread');
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
        $this->assertFalse(
            $copy->getIsPinned(),
            'Pin state should not transfer to a fresh clone.',
        );
        $this->assertFalse(
            $copy->getIsLocked(),
            'Lock state should not transfer to a fresh clone.',
        );

        // Source's pin + lock state is intact.
        $reloaded = $this->entityManager->getRepository(Discussion::class)->find($discussion->getId());
        $this->assertTrue($reloaded->getIsPinned());
        $this->assertTrue($reloaded->getIsLocked());
    }

    public function testNonMemberOfSourceGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $source = $this->createSpace($alice, 'Source');
        $project = $this->createProject($alice, 'P', $source);
        $discussion = $this->seedDiscussion($alice, $project, 'Hidden');

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
        $sourceProject = $this->createProject($alice, 'Source', $source);
        $targetProject = $this->createProject($alice, 'Target', $target);
        $discussion = $this->seedDiscussion($alice, $sourceProject, 'D');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => ['project' => '/projects/' . $targetProject->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidProjectIriGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $project = $this->createProject($alice, 'P', $source);
        $discussion = $this->seedDiscussion($alice, $project, 'D');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => ['project' => 'not-a-real-iri'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testAcceptsBareUuidAsTarget(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $sourceProject = $this->createProject($alice, 'Source', $source);
        $targetProject = $this->createProject($alice, 'Target', $target);
        $discussion = $this->seedDiscussion($alice, $sourceProject, 'D');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions/' . $discussion->getId() . '/copy', [
            'json' => ['project' => (string) $targetProject->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('/projects/' . $targetProject->getId(), $client->getResponse()->toArray()['project']);
    }

    private function seedDiscussion(
        User $author,
        Project $project,
        string $title,
        string $body = 'Body',
        string $category = 'general',
    ): Discussion {
        $disc = new Discussion();
        $disc->setProject($project);
        $disc->setAuthor($author);
        $disc->setTitle($title);
        $disc->setBody($body);
        $disc->setCategory($category);
        $this->entityManager->persist($disc);
        $this->entityManager->flush();
        return $disc;
    }

    private function createProject(User $owner, string $title, Space $space): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        $project->setSpace($space);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
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
