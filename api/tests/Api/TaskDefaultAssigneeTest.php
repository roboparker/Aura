<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A new task created in a personal ("Private") space auto-assigns its
 * creator, since that space has exactly one member — see
 * {@see \App\State\TaskOwnerProcessor}. Shared spaces start unassigned.
 */
class TaskDefaultAssigneeTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testPersonalSpaceTaskDefaultsToCreator(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, personal: true);
        $board = $this->createProject($alice, $space, 'Private board');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Solo task', 'board' => '/boards/' . $board->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $assignees = $this->assigneeIris($client);
        $this->assertSame(['/users/' . $alice->getId()], $assignees);
    }

    public function testSharedSpaceTaskStaysUnassigned(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, personal: false);
        $board = $this->createProject($alice, $space, 'Team board');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Team task', 'board' => '/boards/' . $board->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertSame([], $this->assigneeIris($client));
    }

    public function testPersonalSpaceTaskKeepsExplicitAssignees(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, personal: false);
        $this->ensureSpaceMembership($space, $bob);
        $board = $this->createProject($alice, $space, 'Board');

        // Even though the default would add Alice, an explicit assignee list
        // is respected as-is.
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Assigned to Bob',
                'board' => '/boards/' . $board->getId(),
                'assignees' => ['/users/' . $bob->getId()],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertSame(['/users/' . $bob->getId()], $this->assigneeIris($client));
    }

    /**
     * @return list<string>
     */
    private function assigneeIris(\ApiPlatform\Symfony\Bundle\Test\Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $assignees = $body['assignees'] ?? null;
        $this->assertIsArray($assignees);

        return array_values(array_map(
            static fn (mixed $a): string => is_array($a) && is_string($a['@id'] ?? null) ? $a['@id'] : '',
            $assignees,
        ));
    }

    private function createSpace(User $owner, bool $personal): Space
    {
        $space = (new Space())
            ->setName($personal ? Space::PERSONAL_SPACE_NAME : 'Team')
            ->setIsPersonal($personal)
            ->setCreatedBy($owner);
        if ($personal) {
            $space->setVisibility(Space::VISIBILITY_PRIVATE);
        }
        $space->addUserMembership(
            (new SpaceMembership())->setUser($owner)->setRole(Space::ROLE_ADMIN),
        );
        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $space;
    }

    private function createProject(User $owner, Space $space, string $title): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setSpace($space);
        $board->setTitle($title);
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
