<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\CustomField\CustomFieldKind;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Extra branch coverage for {@see \App\Controller\CustomFieldFooterController}
 * that CustomFieldFooterTest doesn't reach: the `?overdue=`, `?dueDate[...]`
 * and `?search=` task-filter arms, plus min/max numeric aggregation.
 */
class CustomFieldFooterEdgeTest extends ApiTestCase
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

    public function testOutsiderGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->seedNumericField($project, 'Estimate', footerKind: 'sum');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testOverdueFilterRestrictsAggregation(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedNumericField($project, 'Estimate', footerKind: 'sum');

        // Overdue task (past due, not completed) counts; future task doesn't.
        $overdue = $this->seedTaskWithValue($alice, $project, $field, 3);
        $overdue->setDueDate(new \DateTimeImmutable('-2 days'));
        $future = $this->seedTaskWithValue($alice, $project, $field, 5);
        $future->setDueDate(new \DateTimeImmutable('+5 days'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers?overdue=true');
        $this->assertResponseIsSuccessful();
        $first = $this->firstFooter($client);
        $this->assertEqualsCanonicalizing(3, $first['value']);
    }

    public function testDueDateBoundFilter(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedNumericField($project, 'Estimate', footerKind: 'sum');

        $early = $this->seedTaskWithValue($alice, $project, $field, 3);
        $early->setDueDate(new \DateTimeImmutable('2026-01-01'));
        $late = $this->seedTaskWithValue($alice, $project, $field, 5);
        $late->setDueDate(new \DateTimeImmutable('2026-12-31'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        // Only the early task is due on/before mid-year → sum is 3.
        $client->request(
            'GET',
            '/projects/' . $project->getId() . '/custom_field_footers?dueDate[before]=2026-06-01',
        );
        $this->assertResponseIsSuccessful();
        $first = $this->firstFooter($client);
        $this->assertEqualsCanonicalizing(3, $first['value']);
    }

    public function testSearchFilterRestrictsAggregation(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedNumericField($project, 'Estimate', footerKind: 'sum');

        $matching = $this->seedTaskWithValue($alice, $project, $field, 3);
        $matching->setTitle('Pineapple harvest');
        $other = $this->seedTaskWithValue($alice, $project, $field, 5);
        $other->setTitle('Mango delivery');
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request(
            'GET',
            '/projects/' . $project->getId() . '/custom_field_footers?search=pineapple',
        );
        $this->assertResponseIsSuccessful();
        $first = $this->firstFooter($client);
        $this->assertEqualsCanonicalizing(3, $first['value']);
    }

    public function testMinAndMaxAggregation(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $minField = $this->seedNumericField($project, 'Low', footerKind: 'min');
        $maxField = $this->seedNumericField($project, 'High', footerKind: 'max');

        $this->seedTaskWithValues($alice, $project, [[$minField, 4], [$maxField, 4]]);
        $this->seedTaskWithValues($alice, $project, [[$minField, 9], [$maxField, 9]]);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $footers = $body['footers'] ?? null;
        $this->assertIsArray($footers);
        $byName = array_column($footers, null, 'name');
        $low = $byName['Low'] ?? null;
        $this->assertIsArray($low);
        $this->assertEqualsCanonicalizing(4, $low['value']);
        $high = $byName['High'] ?? null;
        $this->assertIsArray($high);
        $this->assertEqualsCanonicalizing(9, $high['value']);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function firstFooter(Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $footers = $body['footers'] ?? null;
        $this->assertIsArray($footers);
        $first = $footers[0] ?? null;
        $this->assertIsArray($first);
        return $first;
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

    private function seedNumericField(Project $project, string $name, ?string $footerKind): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setProject($project)
            ->setName($name)
            ->setKind(CustomFieldKind::NUMERIC->value)
            ->setSubtype('float')
            ->setConfig(['multi' => false]);
        if (null !== $footerKind) {
            $field->setFooter(['kind' => $footerKind]);
        }
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    private function seedTaskWithValue(User $owner, Project $project, CustomFieldDefinition $field, mixed $value): Task
    {
        return $this->seedTaskWithValues($owner, $project, [[$field, $value]]);
    }

    /**
     * @param list<array{0: CustomFieldDefinition, 1: mixed}> $pairs
     */
    private function seedTaskWithValues(User $owner, Project $project, array $pairs): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setProject($project);
        $task->setTitle('Task');
        $this->entityManager->persist($task);
        foreach ($pairs as [$field, $value]) {
            $cfv = new CustomFieldValue();
            $cfv->setTask($task);
            $cfv->setDefinition($field);
            $cfv->setValue($value);
            $this->entityManager->persist($cfv);
        }
        return $task;
    }
}
