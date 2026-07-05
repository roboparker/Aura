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
 * Usage-stats endpoints: per-board "filled" counts
 * (`GET /boards/{id}/custom_field_stats`) and per-option usage
 * (`GET /custom_field_definitions/{id}/option_stats`).
 */
class CustomFieldStatsTest extends ApiTestCase
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
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_stats');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testProjectFillStats(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $estimate = $this->seedTextField($board, 'Estimate');
        $owner = $this->seedTextField($board, 'Owner');

        // Three tasks: estimate filled on two (one explicit null = not filled),
        // owner filled on all three.
        $this->seedTaskWithValues($alice, $board, [[$estimate, '3'], [$owner, 'a']]);
        $this->seedTaskWithValues($alice, $board, [[$estimate, '5'], [$owner, 'b']]);
        $this->seedTaskWithValues($alice, $board, [[$estimate, null], [$owner, 'c']]);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/boards/' . $board->getId() . '/custom_field_stats');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();

        $this->assertSame(3, $body['total']);
        $stats = $body['stats'] ?? null;
        $this->assertIsArray($stats);
        $byDef = [];
        foreach ($stats as $row) {
            $this->assertIsArray($row);
            $definition = $row['definition'] ?? null;
            $this->assertIsString($definition);
            $byDef[$definition] = $row['filled'] ?? null;
        }
        $this->assertSame(2, $byDef['/custom_field_definitions/' . $estimate->getId()]);
        $this->assertSame(3, $byDef['/custom_field_definitions/' . $owner->getId()]);
    }

    public function testOptionStatsCountsSelectUsage(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $channel = $this->seedSelectField($board, 'Channel', multi: true, options: ['web', 'email', 'social']);

        $this->seedTaskWithValues($alice, $board, [[$channel, ['web', 'email']]]);
        $this->seedTaskWithValues($alice, $board, [[$channel, ['web']]]);
        $this->seedTaskWithValues($alice, $board, [[$channel, ['web', 'social']]]);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/custom_field_definitions/' . $channel->getId() . '/option_stats');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $options = $body['options'] ?? null;
        $this->assertIsArray($options);
        $byKey = [];
        foreach ($options as $row) {
            $this->assertIsArray($row);
            $key = $row['key'] ?? null;
            $this->assertIsString($key);
            $byKey[$key] = $row['count'] ?? null;
        }
        $this->assertSame(3, $byKey['web']);
        $this->assertSame(1, $byKey['email']);
        $this->assertSame(1, $byKey['social']);
    }

    public function testOptionStatsEmptyForNonSelect(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $text = $this->seedTextField($board, 'Notes');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/custom_field_definitions/' . $text->getId() . '/option_stats');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame(['options' => []], $response->toArray());
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

    private function seedTextField(Board $board, string $name): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
            ->setName($name)
            ->setKind(CustomFieldKind::TEXT->value)
            ->setSubtype('text')
            ->setConfig(['multi' => false]);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    /**
     * @param list<string> $options
     */
    private function seedSelectField(Board $board, string $name, bool $multi, array $options): CustomFieldDefinition
    {
        $structured = array_map(static fn (string $o): array => ['key' => $o, 'label' => ucfirst($o)], $options);
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
            ->setName($name)
            ->setKind(CustomFieldKind::SELECT->value)
            ->setSubtype($multi ? 'multi' : 'single')
            ->setConfig(['multi' => $multi, 'options' => $structured]);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
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
