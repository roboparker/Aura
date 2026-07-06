<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\CustomField\CustomFieldKind;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Footer aggregation endpoint (#227): `GET /boards/{id}/custom_field_footers`.
 * Covers each strategy-supported aggregation kind (count, sum, avg,
 * min, max), plus the access scoping that hides cross-space boards
 * behind a 404 and the task-filter pass-through (status / search).
 */
class CustomFieldFooterTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testStrangerGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $board = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_footers');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testEmptyResponseWhenNoFieldsHaveFooters(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $this->seedNumericField($board, 'Estimate', footerKind: null);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame(['footers' => []], $response->toArray());
    }

    public function testNumericSumOverAllTasks(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $field = $this->seedNumericField($board, 'Estimate', footerKind: 'sum');

        $this->seedTaskWithValue($alice, $board, $field, 3);
        $this->seedTaskWithValue($alice, $board, $field, 5);
        $this->seedTaskWithValue($alice, $board, $field, null);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $footers = $body['footers'] ?? null;
        $this->assertIsArray($footers);
        $this->assertCount(1, $footers);
        $first = $footers[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('sum', $first['kind']);
        $this->assertSame('Estimate', $first['name']);
        $this->assertEqualsCanonicalizing(8, $first['value']);
    }

    public function testCountAndAvg(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $countField = $this->seedTextField($board, 'Owner', footerKind: 'count');
        $avgField = $this->seedNumericField($board, 'Hours', footerKind: 'avg');

        $this->seedTaskWithValues($alice, $board, [
            [$countField, 'alice'],
            [$avgField, 4],
        ]);
        $this->seedTaskWithValues($alice, $board, [
            [$avgField, 8],
        ]);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $footers = $body['footers'] ?? null;
        $this->assertIsArray($footers);
        $byName = array_column($footers, null, 'name');
        $owner = $byName['Owner'] ?? null;
        $this->assertIsArray($owner);
        $this->assertSame(1, $owner['value']);
        $hours = $byName['Hours'] ?? null;
        $this->assertIsArray($hours);
        $this->assertEqualsCanonicalizing(6, $hours['value']);
    }

    public function testFilterChainHonouredViaStatus(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $field = $this->seedNumericField($board, 'Estimate', footerKind: 'sum');

        // Two open tasks (3, 5) and one completed (7); status=open
        // should aggregate to 8.
        $this->seedTaskWithValue($alice, $board, $field, 3);
        $this->seedTaskWithValue($alice, $board, $field, 5);
        $completed = $this->seedTaskWithValue($alice, $board, $field, 7);
        $completed->setCompletedOn(new \DateTimeImmutable());
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_footers?status=open');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $footers = $body['footers'] ?? null;
        $this->assertIsArray($footers);
        $first = $footers[0] ?? null;
        $this->assertIsArray($first);
        $this->assertEqualsCanonicalizing(8, $first['value']);

        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_footers?status=completed');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $footers = $body['footers'] ?? null;
        $this->assertIsArray($footers);
        $first = $footers[0] ?? null;
        $this->assertIsArray($first);
        $this->assertEqualsCanonicalizing(7, $first['value']);
    }

    public function testSelectBreakdownCountsPerOption(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $status = $this->seedSelectField($board, 'Status', [
            ['key' => 'backlog', 'label' => 'Backlog'],
            ['key' => 'in_progress', 'label' => 'In progress'],
            ['key' => 'done', 'label' => 'Done'],
        ], footerKind: 'breakdown');

        $this->seedTaskWithValue($alice, $board, $status, 'backlog');
        $this->seedTaskWithValue($alice, $board, $status, 'done');
        $this->seedTaskWithValue($alice, $board, $status, 'done');
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $footers = $body['footers'] ?? null;
        $this->assertIsArray($footers);
        $first = $footers[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('breakdown', $first['kind']);
        // One row per configured option, in order, zero-filled where unused.
        $this->assertSame([
            ['key' => 'backlog', 'label' => 'Backlog', 'count' => 1],
            ['key' => 'in_progress', 'label' => 'In progress', 'count' => 0],
            ['key' => 'done', 'label' => 'Done', 'count' => 2],
        ], $first['value']);
    }

    public function testMultiSelectBreakdownUnrollsEachValue(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $labels = $this->seedMultiSelectField($board, 'Labels', [
            ['key' => 'bug', 'label' => 'Bug'],
            ['key' => 'chore', 'label' => 'Chore'],
        ], footerKind: 'breakdown');

        // One task tagged both, one tagged just bug → bug=2, chore=1.
        $this->seedTaskWithValue($alice, $board, $labels, ['bug', 'chore']);
        $this->seedTaskWithValue($alice, $board, $labels, ['bug']);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_footers');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $footers = $body['footers'] ?? null;
        $this->assertIsArray($footers);
        $first = $footers[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('breakdown', $first['kind']);
        $this->assertSame([
            ['key' => 'bug', 'label' => 'Bug', 'count' => 2],
            ['key' => 'chore', 'label' => 'Chore', 'count' => 1],
        ], $first['value']);
    }

    public function testMoneyFooterEmitsAmountCurrencyShape(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $field = $this->seedMoneyField($board, 'Budget', currency: 'USD', footerKind: 'sum');

        $this->seedTaskWithValue($alice, $board, $field, ['amount' => 1000, 'currency' => 'USD']);
        $this->seedTaskWithValue($alice, $board, $field, ['amount' => 2500, 'currency' => 'USD']);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_footers');
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $footers = $body['footers'] ?? null;
        $this->assertIsArray($footers);
        $first = $footers[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame(['amount' => 3500, 'currency' => 'USD'], $first['value']);
    }

    private function createProject(User $owner, string $title): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        $this->addBoardMember($board, $owner);
        $this->entityManager->persist($board);
        $this->entityManager->flush();
        return $board;
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

    private function seedNumericField(Board $board, string $name, ?string $footerKind): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
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

    private function seedTextField(Board $board, string $name, ?string $footerKind): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
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

    private function seedMoneyField(Board $board, string $name, string $currency, ?string $footerKind): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
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

    /**
     * @param list<array{key: string, label: string}> $options
     */
    private function seedSelectField(Board $board, string $name, array $options, ?string $footerKind): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
            ->setName($name)
            ->setKind(CustomFieldKind::SELECT->value)
            ->setSubtype('single')
            ->setConfig(['multi' => false, 'options' => $options]);
        if (null !== $footerKind) {
            $field->setFooter(['kind' => $footerKind]);
        }
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    /**
     * @param list<array{key: string, label: string}> $options
     */
    private function seedMultiSelectField(Board $board, string $name, array $options, ?string $footerKind): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
            ->setName($name)
            ->setKind(CustomFieldKind::SELECT->value)
            ->setSubtype('multi')
            ->setConfig(['multi' => true, 'options' => $options]);
        if (null !== $footerKind) {
            $field->setFooter(['kind' => $footerKind]);
        }
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    private function seedTaskWithValue(User $owner, Board $board, CustomFieldDefinition $field, mixed $value): Task
    {
        return $this->seedTaskWithValues($owner, $board, [[$field, $value]]);
    }

    /**
     * @param list<array{0: CustomFieldDefinition, 1: mixed}> $pairs
     */
    private function seedTaskWithValues(User $owner, Board $board, array $pairs): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setBoard($board);
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
