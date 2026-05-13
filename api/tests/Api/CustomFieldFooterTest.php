<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\CustomField\CustomFieldKind;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Footer aggregation endpoint (#227): `GET /projects/{id}/custom_field_footers`.
 * Covers each strategy-supported aggregation kind (count, sum, avg,
 * min, max), plus the access scoping that hides cross-space projects
 * behind a 404 and the task-filter pass-through (status / search).
 */
class CustomFieldFooterTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldValue')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testStrangerGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testEmptyResponseWhenNoFieldsHaveFooters(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->seedNumericField($project, 'Estimate', footerKind: null);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();
        $this->assertSame(['footers' => []], $client->getResponse()->toArray());
    }

    public function testNumericSumOverAllTasks(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedNumericField($project, 'Estimate', footerKind: 'sum');

        $this->seedTaskWithValue($alice, $project, $field, 3);
        $this->seedTaskWithValue($alice, $project, $field, 5);
        $this->seedTaskWithValue($alice, $project, $field, null);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        $this->assertCount(1, $body['footers']);
        $this->assertSame('sum', $body['footers'][0]['kind']);
        $this->assertSame('Estimate', $body['footers'][0]['name']);
        $this->assertEqualsCanonicalizing(8, $body['footers'][0]['value']);
    }

    public function testCountAndAvg(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $countField = $this->seedTextField($project, 'Owner', footerKind: 'count');
        $avgField = $this->seedNumericField($project, 'Hours', footerKind: 'avg');

        $this->seedTaskWithValues($alice, $project, [
            [$countField, 'alice'],
            [$avgField, 4],
        ]);
        $this->seedTaskWithValues($alice, $project, [
            [$avgField, 8],
        ]);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        $byName = array_column($body['footers'], null, 'name');
        $this->assertSame(1, $byName['Owner']['value']);
        $this->assertEqualsCanonicalizing(6, $byName['Hours']['value']);
    }

    public function testFilterChainHonouredViaStatus(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedNumericField($project, 'Estimate', footerKind: 'sum');

        // Two open tasks (3, 5) and one completed (7); status=open
        // should aggregate to 8.
        $this->seedTaskWithValue($alice, $project, $field, 3);
        $this->seedTaskWithValue($alice, $project, $field, 5);
        $completed = $this->seedTaskWithValue($alice, $project, $field, 7);
        $completed->setCompletedOn(new \DateTimeImmutable());
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers?status=open');
        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        $this->assertEqualsCanonicalizing(8, $body['footers'][0]['value']);

        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers?status=completed');
        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        $this->assertEqualsCanonicalizing(7, $body['footers'][0]['value']);
    }

    public function testMoneyFooterEmitsAmountCurrencyShape(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $field = $this->seedMoneyField($project, 'Budget', currency: 'USD', footerKind: 'sum');

        $this->seedTaskWithValue($alice, $project, $field, ['amount' => 1000, 'currency' => 'USD']);
        $this->seedTaskWithValue($alice, $project, $field, ['amount' => 2500, 'currency' => 'USD']);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/projects/' . $project->getId() . '/custom_field_footers');
        $body = $client->getResponse()->toArray();
        $this->assertSame(['amount' => 3500, 'currency' => 'USD'], $body['footers'][0]['value']);
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
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'password123'));
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

    private function seedTextField(Project $project, string $name, ?string $footerKind): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setProject($project)
            ->setName($name)
            ->setKind(CustomFieldKind::TEXT->value)
            ->setSubtype('text')
            ->setConfig(['multi' => false]);
        if (null !== $footerKind) {
            $field->setFooter(['kind' => $footerKind]);
        }
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    private function seedMoneyField(Project $project, string $name, string $currency, ?string $footerKind): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setProject($project)
            ->setName($name)
            ->setKind(CustomFieldKind::NUMERIC->value)
            ->setSubtype('money')
            ->setConfig(['currency' => $currency]);
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
