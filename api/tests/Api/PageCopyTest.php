<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Page;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /pages/{id}/copy` — clones a page into a target space (#182).
 */
class PageCopyTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Page')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRequiresAuth(): void
    {
        static::createClient()->request('POST', '/pages/' . str_repeat('0', 36) . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCopiesIntoSourceSpaceByDefault(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $page = $this->seedPage($alice, $source, 'Onboarding', null, 'Welcome to the team.');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $page->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('Onboarding (copy)', $body['title']);
        $this->assertSame('/spaces/' . $source->getId(), $body['space']);

        $this->entityManager->clear();
        $copy = $this->entityManager->getRepository(Page::class)->find($body['id']);
        $this->assertNotNull($copy);
        $this->assertSame('Welcome to the team.', $copy->getBody());
        $this->assertSame((string) $alice->getId(), (string) $copy->getCreatedBy()?->getId());
        // Clone is top-level even if source was top-level — explicit
        // null on parent so we don't accidentally inherit something
        // weird from the source row.
        $this->assertNull($copy->getParent());
    }

    public function testCopiesIntoExplicitTargetSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $page = $this->seedPage($alice, $source, 'Spec');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $page->getId() . '/copy', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame('/spaces/' . $target->getId(), $response->toArray()['space']);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copy = $this->entityManager->getRepository(Page::class)
            ->find($response->toArray()['id']);
        $this->assertNotNull($copy);
        $this->assertSame((string) $target->getId(), (string) $copy->getSpace()?->getId());
    }

    public function testCopyDoesNotDoubleSuffix(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $page = $this->seedPage($alice, $source, 'Notes (copy)');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $page->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame('Notes (copy)', $response->toArray()['title']);
    }

    public function testCopyOwnerIsCurrentUserNotSourceCreator(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $source = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($source, $bob);
        $page = $this->seedPage($alice, $source, 'Aliceʼs page');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/pages/' . $page->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copy = $this->entityManager->getRepository(Page::class)
            ->find($response->toArray()['id']);
        $this->assertNotNull($copy);
        $this->assertSame((string) $bob->getId(), (string) $copy->getCreatedBy()?->getId());
    }

    public function testCopyDropsParentEvenWithinSameSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $parent = $this->seedPage($alice, $source, 'Parent');
        $child = $this->seedPage($alice, $source, 'Child', $parent);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $child->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copy = $this->entityManager->getRepository(Page::class)
            ->find($response->toArray()['id']);
        $this->assertNotNull($copy);
        $this->assertNull(
            $copy->getParent(),
            'A copy is always top-level — descendants do not transplant in v1.',
        );

        // Source's parent + child stay intact.
        $reloadedChild = $this->entityManager->getRepository(Page::class)->find($child->getId());
        $this->assertNotNull($reloadedChild);
        $this->assertSame(
            (string) $parent->getId(),
            (string) $reloadedChild->getParent()?->getId(),
        );
    }

    public function testNonMemberOfSourceGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $source = $this->createSpace($alice, 'Source');
        $page = $this->seedPage($alice, $source, 'Hidden');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/pages/' . $page->getId() . '/copy', [
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
        $page = $this->seedPage($alice, $source, 'Page');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/pages/' . $page->getId() . '/copy', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidSpaceIriGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $page = $this->seedPage($alice, $source, 'Page');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $page->getId() . '/copy', [
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
        $page = $this->seedPage($alice, $source, 'Page');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $page->getId() . '/copy', [
            'json' => ['space' => (string) $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame('/spaces/' . $target->getId(), $response->toArray()['space']);
    }

    public function testIncludeDescendantsFalseLeavesSubtreeAlone(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $parent = $this->seedPage($alice, $source, 'Parent');
        $this->seedPage($alice, $source, 'Child', $parent);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $parent->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame(0, $response->toArray()['descendantsCloned']);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copyId = $response->toArray()['id'];
        $copyChildren = $this->entityManager->getRepository(Page::class)
            ->findBy(['parent' => $copyId]);
        $this->assertCount(0, $copyChildren);
    }

    public function testIncludeDescendantsClonesFullSubtreeMirroringHierarchy(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $root = $this->seedPage($alice, $source, 'Root');
        $childA = $this->seedPage($alice, $source, 'Child A', $root, 'A body');
        $childB = $this->seedPage($alice, $source, 'Child B', $root);
        $grandA = $this->seedPage($alice, $source, 'Grandchild A1', $childA);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $root->getId() . '/copy', [
            'json' => ['includeDescendants' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame(3, $response->toArray()['descendantsCloned']);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copyRootId = $response->toArray()['id'];
        $copyRoot = $this->entityManager->getRepository(Page::class)->find($copyRootId);
        $this->assertNotNull($copyRoot);
        $this->assertNull($copyRoot->getParent(), 'Cloned root stays top-level in the target.');
        $this->assertSame('Root (copy)', $copyRoot->getTitle());

        $copyChildren = $this->entityManager->getRepository(Page::class)
            ->findBy(['parent' => $copyRoot], ['title' => 'ASC']);
        $this->assertCount(2, $copyChildren);
        $this->assertSame('Child A', $copyChildren[0]->getTitle());
        $this->assertSame('A body', $copyChildren[0]->getBody());
        $this->assertNull($copyChildren[0]->getParent()?->getParent(), "Child's parent has no grandparent (it's the cloned root).");
        $this->assertSame('Child B', $copyChildren[1]->getTitle());
        // Descendants don't carry the " (copy)" suffix — only the root.
        $this->assertSame('Grandchild A1', $this->entityManager->getRepository(Page::class)
            ->findOneBy(['parent' => $copyChildren[0]])?->getTitle());

        // Source's subtree still intact.
        $sourceChildren = $this->entityManager->getRepository(Page::class)
            ->findBy(['parent' => $root]);
        $this->assertCount(2, $sourceChildren);
    }

    public function testIncludeDescendantsCarriesIntoOtherSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $root = $this->seedPage($alice, $source, 'Root');
        $this->seedPage($alice, $source, 'Child', $root);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $root->getId() . '/copy', [
            'json' => ['space' => '/spaces/' . $target->getId(), 'includeDescendants' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $copyRoot = $this->entityManager->getRepository(Page::class)
            ->find($response->toArray()['id']);
        $this->assertNotNull($copyRoot);
        $this->assertSame((string) $target->getId(), (string) $copyRoot->getSpace()?->getId());
        $copyChild = $this->entityManager->getRepository(Page::class)
            ->findOneBy(['parent' => $copyRoot]);
        $this->assertNotNull($copyChild);
        $this->assertSame(
            (string) $target->getId(),
            (string) $copyChild->getSpace()?->getId(),
            'Descendant clones land in the target space too.',
        );
    }

    private function seedPage(User $author, Space $space, string $title, ?Page $parent = null, string $body = 'Body'): Page
    {
        $page = new Page();
        $page->setSpace($space);
        $page->setCreatedBy($author);
        $page->setTitle($title);
        $page->setBody($body);
        if (null !== $parent) {
            $page->setParent($parent);
        }
        $this->entityManager->persist($page);
        $this->entityManager->flush();
        return $page;
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
