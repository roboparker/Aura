<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Discussion;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DiscussionTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListRequiresAuth(): void
    {
        static::createClient()->request('GET', '/discussions');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMemberCanCreate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->addProjectMember($project,$bob);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/discussions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'title' => 'Idea: switch to pnpm',
                'body' => 'Should be a quick win.',
                'category' => 'ideas',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Author stamped server-side, not from payload.
        $row = $this->entityManager->getRepository(Discussion::class)
            ->findOneBy(['title' => 'Idea: switch to pnpm']);
        $this->assertNotNull($row);
        $this->assertSame(
            (string) $bob->getId(),
            (string) $row->getAuthor()?->getId(),
        );
    }

    public function testNonMemberCannotCreate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/discussions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'title' => 'Sneaky',
                'body' => 'Body',
                'category' => 'general',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // ProjectAccessExtension hides Alice's project from the
        // stranger during IRI resolution, so denormalization fails
        // before securityPostDenormalize runs — same shape as the
        // CommentTest cross-task case.
        $this->assertResponseStatusCodeSame(400);
    }

    public function testInvalidCategoryRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/discussions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'title' => 'Wrong cat',
                'body' => 'Body',
                'category' => 'rumors',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testListFiltersToProjectsTheUserBelongsTo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceProject = $this->createProject($alice, 'Alice');
        $bobProject = $this->createProject($bob, 'Bob');
        $this->seed($alice, $aliceProject, 'A1');
        $this->seed($bob, $bobProject, 'B1');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/discussions');
        $this->assertResponseIsSuccessful();
        $titles = array_map(
            fn ($d) => $d['title'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        $this->assertSame(['A1'], $titles);
    }

    public function testCanFilterByCategory(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->seed($alice, $project, 'General one', 'general');
        $this->seed($alice, $project, 'Idea one', 'ideas');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/discussions?category=ideas');
        $this->assertResponseIsSuccessful();
        $titles = array_map(
            fn ($d) => $d['title'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        $this->assertSame(['Idea one'], $titles);
    }

    public function testAuthorCanEdit(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $disc = $this->seed($alice, $project, 'Original');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/discussions/' . $disc->getId(), [
            'json' => ['title' => 'Edited'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Discussion::class)->find($disc->getId());
        $this->assertSame('Edited', $reloaded?->getTitle());
        $this->assertNotNull($reloaded?->getUpdatedAt());
    }

    public function testNonAuthorMemberCannotEdit(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->addProjectMember($project,$bob);
        $this->entityManager->flush();
        $aliceDiscussion = $this->seed($alice, $project, 'Original');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/discussions/' . $aliceDiscussion->getId(), [
            'json' => ['title' => 'Bob editing'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testProjectOwnerCanPinAnotherMembersDiscussion(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->addProjectMember($project,$bob);
        $this->entityManager->flush();
        $bobDiscussion = $this->seed($bob, $project, 'Bob post');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/discussions/' . $bobDiscussion->getId(), [
            'json' => ['isPinned' => true],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testStrangerCannotReadDiscussion(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $project = $this->createProject($alice, 'Backend');
        $disc = $this->seed($alice, $project, 'Hidden');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/discussions/' . $disc->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testProjectOwnerCanDeleteOthersDiscussion(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->addProjectMember($project,$bob);
        $this->entityManager->flush();
        $bobDiscussion = $this->seed($bob, $project, 'Off-topic');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/discussions/' . $bobDiscussion->getId());
        $this->assertResponseStatusCodeSame(204);
    }

    private function seed(User $author, Project $project, string $title, string $category = 'general'): Discussion
    {
        $disc = new Discussion();
        $disc->setProject($project);
        $disc->setAuthor($author);
        $disc->setTitle($title);
        $disc->setBody('Body for ' . $title);
        $disc->setCategory($category);
        $this->entityManager->persist($disc);
        $this->entityManager->flush();
        return $disc;
    }

    private function createProject(User $owner, string $title): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        $this->addProjectMember($project,$owner);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
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
