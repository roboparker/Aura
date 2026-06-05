<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Search match provenance (`Task.searchMatches`) and the
 * relevance/most-recent sort toggle on `GET /tasks?search=`.
 */
class TaskSearchProvenanceTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldValue')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCommentMatchReportsProvenance(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Untitled task');

        $comment = new Comment();
        $comment->setTask($task);
        $comment->setAuthor($alice);
        $comment->setBody('We discussed the moonshot rollout in standup.');
        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=moonshot');

        $this->assertResponseIsSuccessful();
        $members = $this->members($client);
        $this->assertCount(1, $members);
        $matches = $members[0]['searchMatches'] ?? null;
        $this->assertIsArray($matches);
        $this->assertCount(1, $matches);
        $match = $matches[0];
        $this->assertIsArray($match);
        $this->assertSame('comment', $match['source'] ?? null);
        $this->assertSame('Test User', $match['label'] ?? null);
        $snippet = $match['snippet'] ?? null;
        $this->assertIsString($snippet);
        $this->assertStringContainsStringIgnoringCase('moonshot', $snippet);
        // No HTML marks leak — the client re-highlights the clean fragment.
        $this->assertStringNotContainsString('<b>', $snippet);
    }

    public function testCustomFieldMatchReportsProvenance(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Marketing');
        $task = $this->createTaskInProject($alice, $project, 'Untitled task');
        $field = $this->seedTextField($project, 'Channel');
        $this->seedValue($task, $field, 'moonshot newsletter');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=moonshot');

        $this->assertResponseIsSuccessful();
        $members = $this->members($client);
        $this->assertCount(1, $members);
        $matches = $members[0]['searchMatches'] ?? null;
        $this->assertIsArray($matches);
        $this->assertCount(1, $matches);
        $match = $matches[0];
        $this->assertIsArray($match);
        $this->assertSame('custom_field', $match['source'] ?? null);
        $this->assertSame('Channel', $match['label'] ?? null);
        $snippet = $match['snippet'] ?? null;
        $this->assertIsString($snippet);
        $this->assertStringContainsStringIgnoringCase('moonshot', $snippet);
    }

    public function testTitleMatchHasNoProvenance(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createTask($alice, 'Plan the launch checklist');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=launch');

        $this->assertResponseIsSuccessful();
        $members = $this->members($client);
        $this->assertCount(1, $members);
        // Title/description matches are highlighted client-side, so the
        // backend reports no hidden-source provenance.
        $this->assertSame([], $members[0]['searchMatches'] ?? null);
    }

    public function testSortRecentOrdersNewestFirst(): void
    {
        $alice = $this->createUser('alice@example.com');
        $older = $this->createTask($alice, 'Launch alpha');
        $newer = $this->createTask($alice, 'Launch beta');
        $this->setCreatedOn($older, '-2 hours');
        $this->setCreatedOn($newer, '-1 hour');
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=launch&sort=recent');

        $this->assertResponseIsSuccessful();
        $members = $this->members($client);
        $titles = array_map(
            static fn (array $t): mixed => $t['title'] ?? null,
            $members,
        );
        $this->assertSame(['Launch beta', 'Launch alpha'], $titles);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function members(\ApiPlatform\Symfony\Bundle\Test\Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);

        /** @var array<int, array<string, mixed>> $members */
        return $members;
    }

    private function setCreatedOn(Task $task, string $modify): void
    {
        $ref = new \ReflectionProperty(Task::class, 'createdOn');
        $ref->setValue($task, new \DateTimeImmutable($modify));
    }

    private function seedTextField(Project $project, string $name): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setProject($project);
        $field->setName($name);
        $field->setType('text');
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

    private function createTaskInProject(User $owner, Project $project, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setProject($project);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
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
}
