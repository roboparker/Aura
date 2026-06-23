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
 * Converting a custom field's (kind, subtype, config) migrates existing
 * values when possible and only deletes the rest after the caller
 * confirms — see {@see \App\State\CustomFieldDefinitionUpdateProcessor}
 * and {@see \App\CustomField\CustomFieldValueConverter}.
 */
class CustomFieldConversionTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldValue')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testIntToMoneyScalesAndKeepsValues(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Estimate', 'numeric', 'int');
        $task = $this->createTask($alice, $project, 'A');
        $cfv = $this->seedValue($task, $field, 5);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/custom_field_definitions/' . $field->getId(), [
            'json' => [
                'kind' => 'numeric',
                'subtype' => 'money',
                'config' => ['currency' => 'USD'],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(CustomFieldValue::class)->find($cfv->getId());
        $this->assertNotNull($reloaded);
        // 5 → 5.00 → 500 minor units.
        $this->assertSame(['amount' => 500, 'currency' => 'USD'], $reloaded->getValue());
    }

    public function testDecimalToIntRoundsToNearest(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Score', 'numeric', 'float');
        $task = $this->createTask($alice, $project, 'A');
        $cfv = $this->seedValue($task, $field, 5.7);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/custom_field_definitions/' . $field->getId(), [
            'json' => ['kind' => 'numeric', 'subtype' => 'int', 'config' => []],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(CustomFieldValue::class)->find($cfv->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame(6, $reloaded->getValue());
    }

    public function testSingleToMultiWrapsValue(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Tags', 'text', 'text');
        $task = $this->createTask($alice, $project, 'A');
        $cfv = $this->seedValue($task, $field, 'urgent');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/custom_field_definitions/' . $field->getId(), [
            'json' => ['kind' => 'text', 'subtype' => 'text', 'config' => ['multi' => true]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(CustomFieldValue::class)->find($cfv->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame(['urgent'], $reloaded->getValue());
    }

    public function testUnconvertibleWithoutConfirmationIsBlocked(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Notes', 'text', 'text');
        $task = $this->createTask($alice, $project, 'A');
        $cfv = $this->seedValue($task, $field, 'not a number');

        $client = static::createClient();
        $client->loginUser($alice);

        // text "not a number" can't become an int → blocked pending confirmation.
        $client->request('PATCH', '/custom_field_definitions/' . $field->getId(), [
            'json' => ['kind' => 'numeric', 'subtype' => 'int', 'config' => []],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame(['1'], $response->getHeaders(false)['x-conversion-lost-count'] ?? []);

        // Nothing was persisted: the field keeps its type and the value survives.
        $this->entityManager->clear();
        $stillText = $this->entityManager->getRepository(CustomFieldDefinition::class)->find($field->getId());
        $this->assertNotNull($stillText);
        $this->assertSame('text.text', $stillText->getTypeKey());
        $this->assertNotNull($this->entityManager->getRepository(CustomFieldValue::class)->find($cfv->getId()));
    }

    public function testUnconvertibleWithConfirmationConvertsAndDeletes(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Notes', 'text', 'text');
        $task = $this->createTask($alice, $project, 'A');
        $cfv = $this->seedValue($task, $field, 'not a number');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/custom_field_definitions/' . $field->getId() . '?confirmValueConversion=1', [
            'json' => ['kind' => 'numeric', 'subtype' => 'int', 'config' => []],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $this->assertNull($this->entityManager->getRepository(CustomFieldValue::class)->find($cfv->getId()));
        $converted = $this->entityManager->getRepository(CustomFieldDefinition::class)->find($field->getId());
        $this->assertNotNull($converted);
        $this->assertSame('numeric.int', $converted->getTypeKey());
    }

    public function testConvertibleAndUnconvertibleMix(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedField($project, 'Notes', 'text', 'text');
        $task1 = $this->createTask($alice, $project, 'A');
        $task2 = $this->createTask($alice, $project, 'B');
        $keep = $this->seedValue($task1, $field, '42');
        $drop = $this->seedValue($task2, $field, 'banana');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/custom_field_definitions/' . $field->getId() . '?confirmValueConversion=1', [
            'json' => ['kind' => 'numeric', 'subtype' => 'int', 'config' => []],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $keepReloaded = $this->entityManager->getRepository(CustomFieldValue::class)->find($keep->getId());
        $this->assertNotNull($keepReloaded);
        $this->assertSame(42, $keepReloaded->getValue());
        $this->assertNull($this->entityManager->getRepository(CustomFieldValue::class)->find($drop->getId()));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function seedField(
        Project $project,
        string $name,
        string $kind,
        string $subtype,
        array $config = [],
    ): CustomFieldDefinition {
        $field = new CustomFieldDefinition();
        $field->setProject($project);
        $field->setName($name);
        $field->setKind($kind);
        $field->setSubtype($subtype);
        $field->setConfig($config);
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
