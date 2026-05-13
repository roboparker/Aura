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
 * `POST /pages/{id}/move` — relocates a page (plus every descendant
 * in its subtree) into a different space (#182).
 */
class PageMoveTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Page')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRequiresAuth(): void
    {
        static::createClient()->request('POST', '/pages/' . str_repeat('0', 36) . '/move', [
            'json' => ['space' => '/spaces/x'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMemberOfBothSpacesCanMovePageWithSubtree(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $root = $this->seedPage($alice, $source, 'Root');
        $child = $this->seedPage($alice, $source, 'Child', $root);
        $grandchild = $this->seedPage($alice, $source, 'Grandchild', $child);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $root->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue($client->getResponse()->toArray()['moved']);

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(Page::class);
        foreach ([$root, $child, $grandchild] as $original) {
            $reloaded = $repo->find($original->getId());
            $this->assertSame(
                (string) $target->getId(),
                (string) $reloaded->getSpace()->getId(),
                "Page '{$original->getTitle()}' should follow the move.",
            );
        }
    }

    public function testMoveClearsCrossSpaceParent(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $parent = $this->seedPage($alice, $source, 'Stays');
        $movable = $this->seedPage($alice, $source, 'Goes', $parent);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $movable->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Page::class)->find($movable->getId());
        $this->assertNull(
            $reloaded->getParent(),
            'Moving across spaces must clear the cross-space parent FK.',
        );

        // The parent stays in the source space, untouched.
        $reloadedParent = $this->entityManager->getRepository(Page::class)->find($parent->getId());
        $this->assertSame(
            (string) $source->getId(),
            (string) $reloadedParent->getSpace()->getId(),
        );
    }

    public function testNonMemberOfSourceGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($stranger, 'Target');
        $page = $this->seedPage($alice, $source, 'Hidden');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/pages/' . $page->getId() . '/move', [
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
        $page = $this->seedPage($alice, $source, 'Page');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/pages/' . $page->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testMissingSpacePayloadGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $page = $this->seedPage($alice, $source, 'Page');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $page->getId() . '/move', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testInvalidSpaceIriGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $page = $this->seedPage($alice, $source, 'Page');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $page->getId() . '/move', [
            'json' => ['space' => 'not-a-real-iri'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveToCurrentSpaceIsNoOp(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $page = $this->seedPage($alice, $source, 'Page');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $page->getId() . '/move', [
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
        $page = $this->seedPage($alice, $source, 'Page');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages/' . $page->getId() . '/move', [
            'json' => ['space' => (string) $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue($client->getResponse()->toArray()['moved']);
    }

    private function seedPage(User $author, Space $space, string $title, ?Page $parent = null): Page
    {
        $page = new Page();
        $page->setSpace($space);
        $page->setCreatedBy($author);
        $page->setTitle($title);
        $page->setBody('Body');
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
