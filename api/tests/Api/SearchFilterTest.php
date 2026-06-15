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
 * Functional coverage for the Postgres full-text-search collection
 * filters {@see \App\Filter\ProjectSearchFilter} and
 * {@see \App\Filter\DiscussionSearchFilter}.
 *
 * Both filters expose `?search={q}` (their `PARAMETER` constant) on the
 * Project / Discussion collections, run it through `websearch_to_tsquery`
 * via the `SEARCH_VECTOR_MATCH` DQL function, and order by `ts_rank`
 * (`SEARCH_VECTOR_RANK`) unless `?sort=recent` is supplied. Rows are
 * space-scoped by the access extensions, so the corpus is seeded inside
 * a space the requesting user belongs to.
 */
class SearchFilterTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Discussion')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testProjectSearchMatchesTitleWordOnly(): void
    {
        $alice = $this->createUser('alice@example.com');
        // Projects land in the creator's personal space, of which the
        // creator is the sole admin — so they're visible to Alice.
        $this->createProject($alice, 'Marketing launch checklist', 'Plan the campaign');
        $this->createProject($alice, 'Engineering backlog', 'Refactor the parser');

        $client = static::createClient();
        $client->loginUser($alice);

        // `search` is ProjectSearchFilter::PARAMETER.
        $client->request('GET', '/projects?search=Marketing');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 1]);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $first = $members[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('Marketing launch checklist', $first['title'] ?? null);
    }

    public function testProjectSearchMatchesDescriptionWord(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createProject($alice, 'Marketing launch checklist', 'Plan the campaign');
        $this->createProject($alice, 'Engineering backlog', 'Refactor the parser');

        $client = static::createClient();
        $client->loginUser($alice);

        // "parser" only appears in the second project's description.
        $client->request('GET', '/projects?search=parser');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 1]);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $first = $members[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('Engineering backlog', $first['title'] ?? null);
    }

    public function testProjectSearchNonMatchingTermReturnsNothing(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createProject($alice, 'Marketing launch checklist', 'Plan the campaign');
        $this->createProject($alice, 'Engineering backlog', 'Refactor the parser');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('GET', '/projects?search=zzzznomatch');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 0]);
    }

    public function testProjectSearchRelevanceAndRecentSortsBothSucceed(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createProject($alice, 'Marketing launch alpha', 'shared keyword corpus');
        $this->createProject($alice, 'Marketing launch beta', 'shared keyword corpus');

        $client = static::createClient();
        $client->loginUser($alice);

        // Default ordering is by relevance (ts_rank). Both projects share
        // the search term, so the matched set is the same in either mode.
        $client->request('GET', '/projects?search=Marketing&sort=relevance');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);

        // `sort=recent` swaps the order to createdOn DESC (see
        // ProjectSearchFilter); the matched set is unchanged.
        $client->request('GET', '/projects?search=Marketing&sort=recent');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(2, $members);
    }

    public function testDiscussionSearchMatchesTitleWordOnly(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Backend');
        $this->seedDiscussion($alice, $space, 'Pricing question for launch', 'How much will it cost');
        $this->seedDiscussion($alice, $space, 'Idea: switch to pnpm', 'Quick win on installs');

        $client = static::createClient();
        $client->loginUser($alice);

        // `search` is DiscussionSearchFilter::PARAMETER.
        $client->request('GET', '/discussions?search=Pricing');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 1]);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $first = $members[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('Pricing question for launch', $first['title'] ?? null);
    }

    public function testDiscussionSearchMatchesBodyWord(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Backend');
        $this->seedDiscussion($alice, $space, 'Pricing question for launch', 'How much will it cost');
        $this->seedDiscussion($alice, $space, 'Idea: switch to pnpm', 'Quick win on installs');

        $client = static::createClient();
        $client->loginUser($alice);

        // "installs" only appears in the second discussion's body.
        $client->request('GET', '/discussions?search=installs');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 1]);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $first = $members[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('Idea: switch to pnpm', $first['title'] ?? null);
    }

    public function testDiscussionSearchNonMatchingTermReturnsNothing(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Backend');
        $this->seedDiscussion($alice, $space, 'Pricing question for launch', 'How much will it cost');
        $this->seedDiscussion($alice, $space, 'Idea: switch to pnpm', 'Quick win on installs');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('GET', '/discussions?search=zzzznomatch');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 0]);
    }

    public function testDiscussionSearchKeepsPinnedThreadFirst(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Backend');
        // Two threads share the "launch" term; the pinned one must lead
        // regardless of relevance ranking (see DiscussionSearchFilter,
        // which orders isPinned DESC before the rank tiebreaker).
        $this->seedDiscussion($alice, $space, 'Unpinned launch note', 'launch launch launch detail');
        $this->seedDiscussion($alice, $space, 'Pinned launch note', 'launch summary', true);

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('GET', '/discussions?search=launch');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(2, $members);
        $first = $members[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('Pinned launch note', $first['title'] ?? null);
    }

    private function createProject(User $owner, string $title, string $description): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        $project->setDescription($description);

        // ProjectSpaceDefaultListener fills Project.space with the
        // owner's personal space at PrePersist, so the owner can see it.
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function createSpace(User $admin, string $name): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $membership = (new SpaceMembership())
            ->setUser($admin)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($membership);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
        return $space;
    }

    private function seedDiscussion(
        User $author,
        Space $space,
        string $title,
        string $body,
        bool $pinned = false,
    ): Discussion {
        $disc = new Discussion();
        $disc->setSpace($space);
        $disc->setAuthor($author);
        $disc->setTitle($title);
        $disc->setBody($body);
        $disc->setCategory('general');
        $disc->setIsPinned($pinned);
        $this->entityManager->persist($disc);
        $this->entityManager->flush();
        return $disc;
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
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
