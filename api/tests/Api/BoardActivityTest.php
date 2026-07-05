<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\CustomField\CustomFieldKind;
use App\Entity\CustomFieldDefinition;
use App\Entity\Board;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Board activity feed: `GET /boards/{id}/activity`. Merges the board's own
 * history with its tasks', and keeps a deleted task's history (ending in a
 * remove event) by recovering it from the versioned `board` on the log rows.
 */
class BoardActivityTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ActivityLog')->execute();
    }

    public function testDeletedTaskStaysInBoardActivity(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);

        $created = $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Ship it',
                'board' => '/boards/' . $board->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $iri = $created['@id'] ?? null;
        self::assertIsString($iri);

        // Delete the task — its audit rows outlive the entity.
        $client->request('DELETE', $iri);
        $this->assertResponseStatusCodeSame(204);

        // The board's activity still surfaces the task's create history plus a
        // remove event, rather than dropping it with the row.
        $client->request('GET', '/boards/' . $board->getId() . '/activity');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();

        $items = $body['items'] ?? null;
        $this->assertIsArray($items);
        $taskActions = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            if (($item['objectClass'] ?? null) === 'Task') {
                $taskActions[] = $item['action'] ?? null;
            }
        }
        $this->assertContains('create', $taskActions, 'deleted task create history is retained');
        $this->assertContains('remove', $taskActions, 'deletion is recorded as a remove event on the board');
    }

    public function testCustomFieldActivityAppearsInBoardActivity(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');

        // A custom-field definition's lifecycle is Gedmo-logged with the
        // versioned board, so it should surface on the board's activity feed.
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
            ->setName('Priority')
            ->setKind(CustomFieldKind::TEXT->value)
            ->setSubtype('text')
            ->setConfig(['multi' => false])
            ->setPosition(0);
        $this->entityManager->persist($field);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('GET', '/boards/' . $board->getId() . '/activity');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();

        $items = $body['items'] ?? null;
        $this->assertIsArray($items);
        $cfdActions = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            if (($item['objectClass'] ?? null) === 'CustomFieldDefinition') {
                $cfdActions[] = $item['action'] ?? null;
            }
        }
        $this->assertContains('create', $cfdActions, 'custom-field activity is folded into the board feed');
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
}
