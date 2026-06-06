<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Task;
use App\Entity\User;
use App\Service\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AccountLifecycleTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testDeactivateRequiresStepUpAndFlagsAccount(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);

        // No password → rejected.
        $client->request('POST', '/me/deactivate', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);

        $client->request('POST', '/me/deactivate', [
            'json' => ['currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(User::class)->find($alice->getId());
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->isDeactivated());
    }

    public function testExportReturnsOwnData(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->makeTask($alice, 'Export me');
        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('POST', '/me/export', [
            'json' => ['currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('alice@example.com', $content);
        $this->assertStringContainsString('Export me', $content);
    }

    public function testDeleteRejectsWrongEmail(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('POST', '/me/delete', [
            'json' => ['confirmEmail' => 'nope@example.com', 'currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testDeleteAnonymizesContentToSentinel(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Survives deletion');
        $taskId = (string) $task->getId();
        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('POST', '/me/delete', [
            'json' => ['confirmEmail' => 'alice@example.com', 'currentPassword' => 'Password123!@#'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        // Alice is gone.
        $this->assertNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'alice@example.com']));
        // Her task survives, reassigned to the "Former member" sentinel.
        $survivor = $this->entityManager->getRepository(Task::class)->find($taskId);
        $this->assertNotNull($survivor);
        $this->assertSame(
            AccountDeletionService::SENTINEL_EMAIL,
            $survivor->getOwner()?->getEmail(),
        );
    }

    private function makeTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
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
