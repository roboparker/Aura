<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Change-log endpoint: `GET /projects/{id}/custom_field_definitions/activity`.
 * Confirms Gedmo Loggable records create + update on a CustomFieldDefinition
 * and that the project-scoped feed surfaces them, while strangers get 404.
 */
class CustomFieldDefinitionActivityTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ActivityLog')->execute();
    }

    public function testActivityRecordsCreateAndUpdate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);

        $created = $client->request('POST', '/custom_field_definitions', [
            'json' => [
                'project' => '/projects/' . $project->getId(),
                'name' => 'Severity',
                'kind' => 'text',
                'subtype' => 'text',
                'config' => ['multi' => false],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);

        $id = $created['@id'] ?? null;
        self::assertIsString($id);
        $client->request('PATCH', $id, [
            'json' => ['name' => 'Priority'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_definitions/activity');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();

        $this->assertGreaterThanOrEqual(2, $body['totalItems']);
        $items = $body['items'] ?? null;
        $this->assertIsArray($items);
        $actions = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            $actions[] = $item['action'] ?? null;
        }
        $this->assertContains('create', $actions);
        $this->assertContains('update', $actions);
        $first = $items[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('CustomFieldDefinition', $first['objectClass'] ?? null);
    }

    public function testStrangerGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_definitions/activity');
        $this->assertResponseStatusCodeSame(404);
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
