<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TagTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // task_tag rows are cleared automatically via FK cascade when Tasks
        // and Tags are deleted, so no explicit DELETE for the join table.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Tag')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListTagsUnauthenticated(): void
    {
        static::createClient()->request('GET', '/tags');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateTagAuthenticated(): void
    {
        $user = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tags', [
            'json' => [
                'title' => 'Urgent',
                'description' => 'Needs doing today',
                'color' => '#ef4444',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Tag',
            'title' => 'Urgent',
            'description' => 'Needs doing today',
            'color' => '#ef4444',
        ]);

        $tag = $this->reloadTagByTitle('Urgent');
        $this->assertTrue($user->getId()->equals($tag->getOwner()?->getId()));
    }

    public function testCreateTagIgnoresClientSuppliedOwner(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('POST', '/tags', [
            'json' => [
                'title' => 'Sneaky',
                'color' => '#22c55e',
                'owner' => '/users/' . $bob->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $tag = $this->reloadTagByTitle('Sneaky');
        $this->assertTrue($alice->getId()->equals($tag->getOwner()?->getId()));
    }

    public function testCreateTagRequiresTitle(): void
    {
        $user = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tags', [
            'json' => ['title' => '', 'color' => '#22c55e'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCreateTagRejectsInvalidColor(): void
    {
        $user = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tags', [
            'json' => ['title' => 'Bad', 'color' => 'red'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testListTagsOnlyShowsOwnTags(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $this->createTag($alice, 'A1');
        $this->createTag($alice, 'A2');
        $this->createTag($bob, 'B1');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tags');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);
    }

    public function testGetOtherUsersTagReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsTag = $this->createTag($bob, 'Bob only');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tags/' . $bobsTag->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateOwnTag(): void
    {
        $alice = $this->createUser('alice@example.com');
        $tag = $this->createTag($alice, 'Before');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tags/' . $tag->getId(), [
            'json' => ['title' => 'After', 'color' => '#3b82f6'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $reloaded = $this->reloadTagByTitle('After');
        $this->assertSame('#3b82f6', $reloaded->getColor());
    }

    public function testDeleteOwnTag(): void
    {
        $alice = $this->createUser('alice@example.com');
        $tag = $this->createTag($alice, 'Delete me');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/tags/' . $tag->getId());
        $this->assertResponseStatusCodeSame(204);
    }

    public function testAddTagToTaskViaPatch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Write docs');
        $tag = $this->createTag($alice, 'Writing', '#3b82f6');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['tags' => ['/tags/' . $tag->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertCount(1, $reloaded->getTags());
        $this->assertSame('Writing', $reloaded->getTags()->first()->getTitle());
    }

    public function testRemoveTagFromTaskViaPatch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Write docs');
        $keep = $this->createTag($alice, 'Keep', '#22c55e');
        $drop = $this->createTag($alice, 'Drop', '#ef4444');
        $task->addTag($keep);
        $task->addTag($drop);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['tags' => ['/tags/' . $keep->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertCount(1, $reloaded->getTags());
        $this->assertSame('Keep', $reloaded->getTags()->first()->getTitle());
    }

    public function testCannotAttachOtherUsersTag(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceTask = $this->createTask($alice, 'Alice task');
        $bobsTag = $this->createTag($bob, 'Bob only');

        $client = static::createClient();
        $client->loginUser($alice);
        // TagOwnerExtension scopes item lookups, so the IRI resolves to no
        // row for Alice and the serializer rejects the reference.
        $client->request('PATCH', '/tasks/' . $aliceTask->getId(), [
            'json' => ['tags' => ['/tags/' . $bobsTag->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        // 400 because the deserializer cannot resolve the IRI for this user.
        $this->assertResponseStatusCodeSame(400);
    }

    public function testTagAppearsOnTaskCollection(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Visible task');
        $tag = $this->createTag($alice, 'Badge', '#f59e0b');
        $task->addTag($tag);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse()->toArray();

        $this->assertCount(1, $response['member']);
        $this->assertCount(1, $response['member'][0]['tags']);
        $this->assertSame('Badge', $response['member'][0]['tags'][0]['title']);
        $this->assertSame('#f59e0b', $response['member'][0]['tags'][0]['color']);
    }

    public function testDeletingTagRemovesItFromTasks(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Has a tag');
        $tag = $this->createTag($alice, 'Temp', '#ef4444');
        $task->addTag($tag);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/tags/' . $tag->getId());
        $this->assertResponseStatusCodeSame(204);

        // FK cascade on task_tag removes the association; task remains.
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(0, $reloaded->getTags());
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

    private function createTag(User $owner, string $title, string $color = '#6b7280'): Tag
    {
        $tag = new Tag();
        $tag->setOwner($owner);
        $tag->setTitle($title);
        $tag->setColor($color);

        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private function createTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    private function reloadTagByTitle(string $title): Tag
    {
        $this->entityManager->clear();
        $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['title' => $title]);
        self::assertNotNull($tag, sprintf('Expected to find Tag with title "%s".', $title));
        return $tag;
    }
}
