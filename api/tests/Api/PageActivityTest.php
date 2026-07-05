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
 * Coverage for App\Controller\PageActivityController — `GET /pages/{id}/activity`
 * (#183). Mirrors the Task/Board activity controllers: any space member can
 * read the audit feed; non-members and unknown ids get 404 (existence-hiding).
 */
class PageActivityTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\ActivityLog')->execute();
    }

    public function testMemberSeesActivityAfterPageEdit(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice);

        $client = static::createClient();
        $client->loginUser($alice);

        // Create + edit the page over the wire so Gedmo's listener fires.
        $client->request('POST', '/pages', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'title' => 'Runbook',
                'body' => 'First draft.',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $created = $response->toArray();
        $pageId = $created['id'];
        $this->assertIsString($pageId);

        $client->request('PATCH', '/pages/' . $pageId, [
            'json' => ['title' => 'Runbook v2'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/pages/' . $pageId . '/activity');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();

        $this->assertSame(2, $body['totalItems']);
        $items = $body['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertCount(2, $items);
        $latest = $items[0] ?? null;
        $this->assertIsArray($latest);
        $this->assertSame('update', $latest['action']);
        $actors = $body['actors'] ?? null;
        $this->assertIsArray($actors);
        $this->assertArrayHasKey('/users/' . $alice->getId(), $actors);
    }

    public function testActivityPaginates(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice);

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('POST', '/pages', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'title' => 'Pageable',
                'body' => 'Body',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $pageId = $response->toArray()['id'];
        $this->assertIsString($pageId);

        for ($i = 1; $i <= 4; $i++) {
            $client->request('PATCH', '/pages/' . $pageId, [
                'json' => ['title' => 'Pageable v' . $i],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
            ]);
        }

        $client->request('GET', '/pages/' . $pageId . '/activity?page=1&itemsPerPage=2');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame(5, $body['totalItems']);
        $items = $body['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertCount(2, $items);
    }

    public function testStrangerCannotReadActivity(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($alice);
        $page = $this->seedPage($alice, $space, 'Hidden');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/pages/' . $page->getId() . '/activity');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownPageIs404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        // Well-formed UUID that doesn't resolve to a page.
        $client->request('GET', '/pages/00000000-0000-0000-0000-000000000000/activity');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidIdIs404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/pages/not-a-uuid/activity');
        $this->assertResponseStatusCodeSame(404);
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
}
