<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\GlobalCustomFieldDefinition;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task values pointing at an instance-wide global custom field
 * (#global-custom-fields) — the polymorphic `CustomFieldValue.globalDefinition`
 * arm and its per-project opt-in enforcement.
 */
class GlobalCustomFieldValueTest extends ApiTestCase
{
    use SpaceMembershipFixture;
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldValue')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ProjectFieldVisibility')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\GlobalCustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testSetGlobalValueOnAttachedProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $effort = $this->seedGlobal('Effort', 'numeric', 'int');
        $this->attachGlobal($project, $effort);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Estimate',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['globalDefinition' => '/global_custom_field_definitions/' . $effort->getId(), 'value' => 5],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $this->body($client);
        $cfvs = $this->arrayField($body, 'customFieldValues');
        $this->assertCount(1, $cfvs);
        $first = $cfvs[0];
        $this->assertIsArray($first);
        $this->assertSame(
            '/global_custom_field_definitions/' . $effort->getId(),
            $first['globalDefinition'] ?? null,
        );
        $this->assertNull($first['definition'] ?? null);
        $this->assertSame(5, $first['value'] ?? null);
    }

    public function testGlobalValueRejectedWhenNotAttached(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        // Global exists but the project has NOT opted into it.
        $effort = $this->seedGlobal('Effort', 'numeric', 'int');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Estimate',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['globalDefinition' => '/global_custom_field_definitions/' . $effort->getId(), 'value' => 5],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testBothSourcesOnOneValueRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $spaceField = $this->seedSpaceField($project, 'Severity');
        $effort = $this->seedGlobal('Effort', 'numeric', 'int');
        $this->attachGlobal($project, $effort);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Confused',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    [
                        'definition' => '/custom_field_definitions/' . $spaceField->getId(),
                        'globalDefinition' => '/global_custom_field_definitions/' . $effort->getId(),
                        'value' => 'x',
                    ],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRequiredGlobalEnforcedOnCreate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $effort = $this->seedGlobal('Effort', 'numeric', 'int');
        $effort->setNullable(false);
        $this->entityManager->flush();
        $this->attachGlobal($project, $effort);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'No effort',
                'project' => '/projects/' . $project->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchReplacesGlobalValue(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $effort = $this->seedGlobal('Effort', 'numeric', 'int');
        $this->attachGlobal($project, $effort);
        $task = $this->createTask($alice, $project, 'Estimate');
        $this->seedGlobalValue($task, $effort, 3);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => [
                'customFieldValues' => [
                    ['globalDefinition' => '/global_custom_field_definitions/' . $effort->getId(), 'value' => 8],
                ],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $values = $reloaded->getCustomFieldValues()->toArray();
        $this->assertCount(1, $values);
        $this->assertSame(8, $values[0]->getValue());
        $this->assertSame((string) $effort->getId(), (string) $values[0]->getGlobalDefinition()?->getId());
    }

    public function testFooterAggregatesGlobalField(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $effort = $this->seedGlobal('Effort', 'numeric', 'int');
        $effort->setFooter(['kind' => 'sum']);
        $this->entityManager->flush();
        $this->attachGlobal($project, $effort);

        $t1 = $this->createTask($alice, $project, 'A');
        $t2 = $this->createTask($alice, $project, 'B');
        $this->seedGlobalValue($t1, $effort, 3);
        $this->seedGlobalValue($t2, $effort, 5);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();

        $body = $this->body($client);
        $footers = $this->arrayField($body, 'footers');
        $this->assertCount(1, $footers);
        $row = $footers[0];
        $this->assertIsArray($row);
        $this->assertSame('/global_custom_field_definitions/' . $effort->getId(), $row['definition'] ?? null);
        $this->assertSame('sum', $row['kind'] ?? null);
        $this->assertSame(8, $row['value'] ?? null);
    }

    private function attachGlobal(Project $project, GlobalCustomFieldDefinition $global): void
    {
        $project->addGlobalCustomFieldDefinition($global);
        $this->entityManager->flush();
    }

    private function seedGlobal(string $name, string $kind, string $subtype): GlobalCustomFieldDefinition
    {
        $field = new GlobalCustomFieldDefinition();
        $field->setName($name);
        $field->setKind($kind);
        $field->setSubtype($subtype);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    private function seedSpaceField(Project $project, string $name): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setProject($project);
        $field->setName($name);
        $field->setType(CustomFieldDefinition::TYPE_TEXT);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    private function seedGlobalValue(Task $task, GlobalCustomFieldDefinition $definition, mixed $value): CustomFieldValue
    {
        $cfv = new CustomFieldValue();
        $cfv->setTask($task);
        $cfv->setGlobalDefinition($definition);
        $cfv->setValue($value);
        $this->entityManager->persist($cfv);
        $this->entityManager->flush();
        return $cfv;
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

    private function createTask(User $owner, Project $project, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setProject($project);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    /**
     * @param string[] $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
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

    /**
     * @return array<int|string, mixed>
     */
    private function body(\ApiPlatform\Symfony\Bundle\Test\Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        return $response->toArray(false);
    }
}
