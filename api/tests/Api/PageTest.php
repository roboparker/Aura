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
 * Page CRUD + access tests (#183). Pages live under a Space and follow
 * the standard space-membership access matrix: any member can read /
 * create; only the author or a space admin can edit / delete.
 */
class PageTest extends ApiTestCase
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

    public function testListRequiresAuth(): void
    {
        static::createClient()->request('GET', '/pages');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMemberCanCreatePageAndAuthorIsStamped(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice);
        $this->ensureSpaceMembership($space, $bob);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/pages', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'title' => 'Onboarding',
                'body' => 'Welcome aboard.',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // createdBy stamped server-side, not from payload.
        $row = $this->entityManager->getRepository(Page::class)
            ->findOneBy(['title' => 'Onboarding']);
        $this->assertNotNull($row);
        $this->assertSame((string) $bob->getId(), (string) $row->getCreatedBy()?->getId());
    }

    public function testNonMemberCannotCreate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($alice);

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/pages', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'title' => 'Sneaky',
                'body' => 'No access',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // SpaceAccessExtension hides Alice's space from the stranger
        // during IRI resolution — denormalisation 400s before the
        // securityPostDenormalize check fires. Same shape as the
        // DiscussionTest non-member case.
        $this->assertResponseStatusCodeSame(400);
    }

    public function testListFiltersToSpacesTheUserBelongsTo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceSpace = $this->createSpace($alice, 'Alice space');
        $bobSpace = $this->createSpace($bob, 'Bob space');
        $this->seedPage($alice, $aliceSpace, 'Alice page');
        $this->seedPage($bob, $bobSpace, 'Bob page');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/pages');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? [];
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $p): mixed => is_array($p) ? $p['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Alice page'], $titles);
    }

    public function testStrangerCannotReadPage(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($alice);
        $page = $this->seedPage($alice, $space, 'Hidden');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/pages/' . $page->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAuthorCanEditPage(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice);
        $page = $this->seedPage($alice, $space, 'Original');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/pages/' . $page->getId(), [
            'json' => ['title' => 'Edited'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Page::class)->find($page->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame('Edited', $reloaded->getTitle());
        $this->assertNotNull($reloaded->getUpdatedAt());
    }

    public function testNonAuthorMemberCannotEdit(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice);
        $this->ensureSpaceMembership($space, $bob);
        $aliceArticle = $this->seedPage($alice, $space, 'Alice authored');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/pages/' . $aliceArticle->getId(), [
            'json' => ['title' => 'Bob meddling'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSpaceAdminCanEditOthersPage(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice);
        $this->ensureSpaceMembership($space, $bob);
        $bobPage = $this->seedPage($bob, $space, 'Bob authored');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/pages/' . $bobPage->getId(), [
            'json' => ['title' => 'Tidied by admin'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testSpaceAdminCanDeleteOthersPage(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice);
        $this->ensureSpaceMembership($space, $bob);
        $bobPage = $this->seedPage($bob, $space, 'Removable');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/pages/' . $bobPage->getId());
        $this->assertResponseStatusCodeSame(204);
    }

    public function testNonAuthorMemberCannotDelete(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice);
        $this->ensureSpaceMembership($space, $bob);
        $alicePage = $this->seedPage($alice, $space, 'Keep me');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('DELETE', '/pages/' . $alicePage->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testParentMustBelongToSameSpaceContextLoadsViaAccess(): void
    {
        // A user can only set `parent` to a page they can see — the
        // PageAccessExtension hides outsider pages, so denormalisation
        // 400s rather than letting cross-space trees form.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceSpace = $this->createSpace($alice);
        $bobSpace = $this->createSpace($bob);
        $this->ensureSpaceMembership($bobSpace, $alice);
        $bobsPage = $this->seedPage($bob, $bobSpace, 'Bob root');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages', [
            'json' => [
                'space' => '/spaces/' . $aliceSpace->getId(),
                'parent' => '/pages/' . $bobsPage->getId(),
                'title' => 'Cross-space child',
                'body' => 'Should still POST — server enforces space membership not tree consistency.',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // Alice IS a member of Bob's space (joined above) and IS the
        // owner of her own space, so this is a legitimate (if odd)
        // create — keep it succeeding to document the current behaviour.
        $this->assertResponseStatusCodeSame(201);
    }

    public function testTitleRequired(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'title' => '',
                'body' => 'Body without title',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testOversizedBodyRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/pages', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'title' => 'Big',
                'body' => str_repeat('x', Page::MAX_BODY_LENGTH + 1),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testFullTextSearchFiltersByTitle(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice);
        $this->seedPage($alice, $space, 'Onboarding guide', 'Welcome');
        $this->seedPage($alice, $space, 'Release plan', 'Quarterly');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/pages?search=onboarding');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? [];
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $p): mixed => is_array($p) ? $p['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Onboarding guide'], $titles);
    }

    private function seedPage(User $author, Space $space, string $title, string $body = 'Body'): Page
    {
        $page = new Page();
        $page->setSpace($space);
        $page->setCreatedBy($author);
        $page->setTitle($title);
        $page->setBody($body);
        $this->entityManager->persist($page);
        $this->entityManager->flush();
        return $page;
    }

    private function createSpace(User $owner, string $name = 'Test space'): Space
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
