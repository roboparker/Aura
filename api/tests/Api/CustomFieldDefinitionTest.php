<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CustomFieldDefinitionTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListRequiresAuth(): void
    {
        static::createClient()->request('GET', '/custom_field_definitions');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testOwnerCanCreateTextField(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/custom_field_definitions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'name' => 'Severity',
                'kind' => 'text',
                'subtype' => 'text',
                'config' => ['multi' => false],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testNonOwnerMemberCannotCreate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->addProjectMember($project, $bob);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/custom_field_definitions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'name' => 'Severity',
                'kind' => 'text',
                'subtype' => 'text',
                'config' => ['multi' => false],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testStrangerCannotEvenSeeProjectFields(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Severity', 'text');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/custom_field_definitions/' . $field->getId());
        // Cross-project lookups return 404 (extension scopes the query),
        // matching the rest of the per-project resources.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testListFiltersToProjectsTheUserBelongsTo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceProject = $this->createProject($alice, 'Alice');
        $bobProject = $this->createProject($bob, 'Bob');
        $this->seedField($aliceProject, 'A1', 'text');
        $this->seedField($bobProject, 'B1', 'text');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/custom_field_definitions');
        $this->assertResponseIsSuccessful();
        $names = array_map(
            fn ($c) => $c['name'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        $this->assertSame(['A1'], $names);
    }

    public function testDropdownRequiresOptions(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/custom_field_definitions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'name' => 'Priority',
                'kind' => 'select',
                'subtype' => 'single',
                'config' => ['multi' => false],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testDropdownHappyPath(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/custom_field_definitions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'name' => 'Priority',
                'kind' => 'select',
                'subtype' => 'single',
                'config' => [
                    'multi' => false,
                    'options' => [
                        ['key' => 'Low', 'label' => 'Low'],
                        ['key' => 'Medium', 'label' => 'Medium'],
                        ['key' => 'High', 'label' => 'High'],
                    ],
                ],
                'nullable' => false,
                'position' => 1,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            'name' => 'Priority',
            'kind' => 'select',
            'subtype' => 'single',
            'nullable' => false,
            'position' => 1,
        ]);
    }

    public function testInvalidKindRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/custom_field_definitions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'name' => 'Mystery',
                'kind' => 'mystery-shape',
                'subtype' => 'mystery-shape',
                'config' => [],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testDuplicateNameInProjectRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->seedField($project, 'Severity', 'text');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/custom_field_definitions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'name' => 'Severity',
                'kind' => 'text',
                'subtype' => 'text',
                'config' => ['multi' => false],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // Either 409 (Doctrine unique violation surfaced as Conflict) or
        // 422 (validator-driven) — both signal the duplicate.
        $this->assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        $this->assertLessThan(500, $client->getResponse()->getStatusCode());
    }

    public function testOwnerCanDelete(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Severity', 'text');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/custom_field_definitions/' . $field->getId());
        $this->assertResponseStatusCodeSame(204);
    }

    public function testMemberCannotDelete(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->addProjectMember($project, $bob);
        $this->entityManager->flush();
        $field = $this->seedField($project, 'Severity', 'text');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('DELETE', '/custom_field_definitions/' . $field->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    private function seedField(Project $project, string $name, string $type): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setProject($project);
        $field->setName($name);
        $field->setType($type);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    private function createProject(User $owner, string $title): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        $this->addProjectMember($project, $owner);
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
