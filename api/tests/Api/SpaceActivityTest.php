<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coverage for the space-level activity feed (`/spaces/{id}/activity`),
 * which aggregates the audit history of a space's projects, pages, and
 * the tasks inside those projects. Drives entities through the real API
 * so Gedmo's LoggableListener fires.
 */
class SpaceActivityTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Page')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ActivityLog')->execute();
    }

    public function testMemberSeesProjectPageAndTaskActivity(): void
    {
        $owner = $this->createUser('owner@example.com');
        $member = $this->createUser('member@example.com');
        $project = $this->seedProject($owner, [$owner, $member], 'Launch checklist');
        $space = $project->getSpace();
        self::assertNotNull($space);
        $spaceId = (string) $space->getId();

        $client = static::createClient();
        $client->loginUser($owner);

        // Task inside the project → Task CREATE log.
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Press & PR', 'project' => '/projects/' . $project->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Page in the space → Page CREATE log.
        $client->request('POST', '/pages', [
            'json' => ['title' => 'Channel checklist', 'space' => '/spaces/' . $spaceId],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Project edit → Project UPDATE log.
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['description' => 'Updated description'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // The other member reads the aggregated feed.
        $client->loginUser($member);
        $client->request('GET', '/spaces/' . $spaceId . '/activity');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();

        $total = $body['totalItems'] ?? null;
        $this->assertIsInt($total);
        $this->assertGreaterThanOrEqual(3, $total);

        $items = $body['items'] ?? null;
        $this->assertIsArray($items);
        $classes = array_column($items, 'objectClass');
        $this->assertContains('Project', $classes, 'Feed should include the project.');
        $this->assertContains('Task', $classes, 'Feed should include the in-project task.');
        $this->assertContains('Page', $classes, 'Feed should include the space page.');

        // Actor map is keyed by IRI so the PWA can render avatars.
        $actors = $body['actors'] ?? null;
        $this->assertIsArray($actors);
        $this->assertArrayHasKey('/users/' . $owner->getId(), $actors);
    }

    public function testStrangerCannotReadSpaceActivity(): void
    {
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $project = $this->seedProject($owner, [$owner], 'Private');
        $space = $project->getSpace();
        self::assertNotNull($space);

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/spaces/' . $space->getId() . '/activity');

        // 404, not 403 — same enumeration-resistance rule as Space GET.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidIdReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/spaces/not-a-uuid/activity');
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @param User[] $members
     */
    private function seedProject(User $owner, array $members, string $title): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        foreach ($members as $member) {
            $this->addProjectMember($project, $member);
        }
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
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
