<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Per-task custom field values (#84).
 */
class TaskCustomFieldValueTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // Order matters: values FK to task + definition; definitions FK
        // to project. Clear from leaves to roots.
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldValue')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testMemberCanCreateTaskWithCustomFieldValues(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $severity = $this->seedField($project, 'Severity', CustomFieldDefinition::TYPE_TEXT);
        $priority = $this->seedField(
            $project,
            'Priority',
            CustomFieldDefinition::TYPE_DROPDOWN,
            ['Low', 'Medium', 'High'],
        );

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Investigate outage',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $severity->getId(), 'value' => 'critical'],
                    ['definition' => '/custom_field_definitions/' . $priority->getId(), 'value' => 'High'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $client->getResponse()->toArray();
        $this->assertCount(2, $body['customFieldValues']);
        $values = array_combine(
            array_map(fn ($v) => $v['definition'], $body['customFieldValues']),
            array_map(fn ($v) => $v['value'], $body['customFieldValues']),
        );
        $this->assertSame('critical', $values['/custom_field_definitions/' . $severity->getId()]);
        $this->assertSame('High', $values['/custom_field_definitions/' . $priority->getId()]);
    }

    public function testNumberFieldRejectsNonNumeric(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $count = $this->seedField($project, 'Count', CustomFieldDefinition::TYPE_NUMBER);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Some task',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $count->getId(), 'value' => 'twelve'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testNumberFieldAcceptsIntegerAndFloat(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $count = $this->seedField($project, 'Count', CustomFieldDefinition::TYPE_NUMBER);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'A',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $count->getId(), 'value' => 12],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'B',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $count->getId(), 'value' => 1.5],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testCheckboxFieldRejectsNonBool(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $flag = $this->seedField($project, 'Done', CustomFieldDefinition::TYPE_CHECKBOX);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Some task',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $flag->getId(), 'value' => 'yes'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testDateFieldRequiresIsoFormat(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $when = $this->seedField($project, 'Sprint Date', CustomFieldDefinition::TYPE_DATE);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Some task',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $when->getId(), 'value' => '12-04-2026'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'OK task',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $when->getId(), 'value' => '2026-04-12'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testDropdownRejectsValueOutsideOptions(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $priority = $this->seedField(
            $project,
            'Priority',
            CustomFieldDefinition::TYPE_DROPDOWN,
            ['Low', 'High'],
        );

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Some task',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $priority->getId(), 'value' => 'Catastrophic'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRequiredFieldEnforcedOnTaskCreate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $required = $this->seedField($project, 'Severity', CustomFieldDefinition::TYPE_TEXT);
        $required->setRequired(true);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'No severity',
                'project' => '/projects/' . $project->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRequiredFieldNotEnforcedOnPersonalTaskWithoutProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $required = $this->seedField($project, 'Severity', CustomFieldDefinition::TYPE_TEXT);
        $required->setRequired(true);
        $this->entityManager->flush();

        // A task with no project belongs to no field schema, so the
        // required-ness rule on Severity doesn't apply.
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Personal task'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testValuesFromOtherProjectRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->createProject($alice, 'A');
        $b = $this->createProject($alice, 'B');
        $foreignField = $this->seedField($b, 'Severity', CustomFieldDefinition::TYPE_TEXT);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Task in A',
                'project' => '/projects/' . $a->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $foreignField->getId(), 'value' => 'oops'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testDuplicateDefinitionRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Severity', CustomFieldDefinition::TYPE_TEXT);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Some task',
                'project' => '/projects/' . $project->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $field->getId(), 'value' => 'low'],
                    ['definition' => '/custom_field_definitions/' . $field->getId(), 'value' => 'high'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchReplacesValuesAndOrphansRemove(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $severity = $this->seedField($project, 'Severity', CustomFieldDefinition::TYPE_TEXT);
        $count = $this->seedField($project, 'Count', CustomFieldDefinition::TYPE_NUMBER);
        $task = $this->createTask($alice, $project, 'Outage');
        $this->seedValue($task, $severity, 'high');
        $this->seedValue($task, $count, 5);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => [
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $severity->getId(), 'value' => 'low'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // The Count value was orphaned by the new payload and should be
        // gone from the DB; only the Severity row remains.
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $remaining = array_map(
            fn (CustomFieldValue $v) => [$v->getDefinition()?->getName(), $v->getValue()],
            $reloaded->getCustomFieldValues()->toArray(),
        );
        $this->assertCount(1, $remaining);
        $this->assertSame(['Severity', 'low'], $remaining[0]);
    }

    public function testDeletingDefinitionCascadesValues(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $severity = $this->seedField($project, 'Severity', CustomFieldDefinition::TYPE_TEXT);
        $task = $this->createTask($alice, $project, 'Outage');
        $this->seedValue($task, $severity, 'high');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/custom_field_definitions/' . $severity->getId());
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $remaining = $this->entityManager->getRepository(CustomFieldValue::class)->findAll();
        $this->assertSame([], $remaining);
    }

    public function testNonMemberCannotPatchViaCustomValues(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Severity', CustomFieldDefinition::TYPE_TEXT);
        $task = $this->createTask($alice, $project, 'Private task');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => [
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $field->getId(), 'value' => 'oops'],
                ],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        // TaskOwnerExtension scopes the lookup to {owner ∪ project members},
        // so strangers see 404 (matches every other per-task endpoint) rather
        // than the bare 403 that operation-level security would otherwise emit.
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<int, string>|null $options
     */
    private function seedField(
        Project $project,
        string $name,
        string $type,
        ?array $options = null,
    ): CustomFieldDefinition {
        $field = new CustomFieldDefinition();
        $field->setProject($project);
        $field->setName($name);
        $field->setType($type);
        if (null !== $options) {
            $field->setOptions($options);
        }
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    private function seedValue(Task $task, CustomFieldDefinition $definition, mixed $value): CustomFieldValue
    {
        $cfv = new CustomFieldValue();
        $cfv->setTask($task);
        $cfv->setDefinition($definition);
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
