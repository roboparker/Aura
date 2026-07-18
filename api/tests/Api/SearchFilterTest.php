<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Board;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional coverage for the Postgres full-text-search collection
 * filter {@see \App\Filter\BoardSearchFilter}.
 *
 * The filter exposes `?search={q}` (its `PARAMETER` constant) on the
 * Board collection, runs it through `websearch_to_tsquery` via the
 * `SEARCH_VECTOR_MATCH` DQL function, and orders by `ts_rank`
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

        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testProjectSearchMatchesTitleWordOnly(): void
    {
        $alice = $this->createUser('alice@example.com');
        // Boards land in the creator's personal space, of which the
        // creator is the sole admin — so they're visible to Alice.
        $this->createProject($alice, 'Marketing launch checklist', 'Plan the campaign');
        $this->createProject($alice, 'Engineering backlog', 'Refactor the parser');

        $client = static::createClient();
        $client->loginUser($alice);

        // `search` is BoardSearchFilter::PARAMETER.
        $client->request('GET', '/boards?search=Marketing');
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

        // "parser" only appears in the second board's description.
        $client->request('GET', '/boards?search=parser');
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

        $client->request('GET', '/boards?search=zzzznomatch');
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

        // Default ordering is by relevance (ts_rank). Both boards share
        // the search term, so the matched set is the same in either mode.
        $client->request('GET', '/boards?search=Marketing&sort=relevance');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);

        // `sort=recent` swaps the order to createdOn DESC (see
        // BoardSearchFilter); the matched set is unchanged.
        $client->request('GET', '/boards?search=Marketing&sort=recent');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(2, $members);
    }

    private function createProject(User $owner, string $title, string $description): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        $board->setDescription($description);

        // BoardSpaceDefaultListener fills Board.space with the
        // owner's personal space at PrePersist, so the owner can see it.
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
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
