<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Page;
use App\Entity\PageComment;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Comment-on-Page CRUD + access tests (#183). Author is stamped on POST,
 * read scoped to space members, edit author-only, delete author-or-admin.
 */
class PageCommentTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\PageComment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Page')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListRequiresAuth(): void
    {
        static::createClient()->request('GET', '/page_comments');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMemberCanCommentAndAuthorIsStamped(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice);
        $this->ensureSpaceMembership($space, $bob);
        $page = $this->seedPage($alice, $space, 'Notes');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/page_comments', [
            'json' => [
                'page' => '/pages/' . $page->getId(),
                'body' => 'Looks good to me.',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $row = $this->entityManager->getRepository(PageComment::class)
            ->findOneBy(['body' => 'Looks good to me.']);
        $this->assertNotNull($row);
        $this->assertSame((string) $bob->getId(), (string) $row->getAuthor()?->getId());
    }

    public function testNonMemberCannotCommentSeesPageAsHidden(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($alice);
        $page = $this->seedPage($alice, $space, 'Private');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/page_comments', [
            'json' => [
                'page' => '/pages/' . $page->getId(),
                'body' => 'Sneaky',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // PageAccessExtension hides the page during IRI resolution, so
        // denormalisation 400s before the security check fires —
        // mirrors the DiscussionTest non-member case.
        $this->assertResponseStatusCodeSame(400);
    }

    public function testListFiltersToCommentsOnReadablePages(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceSpace = $this->createSpace($alice);
        $bobSpace = $this->createSpace($bob);
        $alicePage = $this->seedPage($alice, $aliceSpace, 'A');
        $bobPage = $this->seedPage($bob, $bobSpace, 'B');
        $this->seedComment($alice, $alicePage, 'In Alice');
        $this->seedComment($bob, $bobPage, 'In Bob');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/page_comments');
        $this->assertResponseIsSuccessful();
        $bodies = array_map(
            fn ($c) => $c['body'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        $this->assertSame(['In Alice'], $bodies);
    }

    public function testStrangerCannotReadCommentItem(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($alice);
        $page = $this->seedPage($alice, $space, 'Page');
        $comment = $this->seedComment($alice, $page, 'Hidden');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/page_comments/' . $comment->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAuthorCanEditOwnComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice);
        $page = $this->seedPage($alice, $space, 'Page');
        $comment = $this->seedComment($alice, $page, 'Original');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/page_comments/' . $comment->getId(), [
            'json' => ['body' => 'Edited'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testNonAuthorMemberCannotEditOthersComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice);
        $this->ensureSpaceMembership($space, $bob);
        $page = $this->seedPage($alice, $space, 'Page');
        $aliceComment = $this->seedComment($alice, $page, 'Alice wrote this');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/page_comments/' . $aliceComment->getId(), [
            'json' => ['body' => 'Bob meddling'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSpaceAdminCanDeleteOthersComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice);
        $this->ensureSpaceMembership($space, $bob);
        $page = $this->seedPage($alice, $space, 'Page');
        $bobComment = $this->seedComment($bob, $page, 'Off-topic');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/page_comments/' . $bobComment->getId());
        $this->assertResponseStatusCodeSame(204);
    }

    public function testNonAuthorMemberCannotDeleteOthersComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $charlie = $this->createUser('charlie@example.com');
        $space = $this->createSpace($alice);
        $this->ensureSpaceMembership($space, $bob);
        $this->ensureSpaceMembership($space, $charlie);
        $page = $this->seedPage($alice, $space, 'Page');
        $bobComment = $this->seedComment($bob, $page, 'Bob wrote this');

        $client = static::createClient();
        $client->loginUser($charlie);
        $client->request('DELETE', '/page_comments/' . $bobComment->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testEmptyBodyRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice);
        $page = $this->seedPage($alice, $space, 'Page');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/page_comments', [
            'json' => [
                'page' => '/pages/' . $page->getId(),
                'body' => '',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    private function seedComment(User $author, Page $page, string $body): PageComment
    {
        $comment = new PageComment();
        $comment->setPage($page);
        $comment->setAuthor($author);
        $comment->setBody($body);
        $this->entityManager->persist($comment);
        $this->entityManager->flush();
        return $comment;
    }

    private function seedPage(User $author, Space $space, string $title): Page
    {
        $page = new Page();
        $page->setSpace($space);
        $page->setCreatedBy($author);
        $page->setTitle($title);
        $page->setBody('Body');
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
