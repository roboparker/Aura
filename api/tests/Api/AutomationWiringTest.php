<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Automation\AutomationEvents;
use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The wiring, exercised through real HTTP (#764).
 *
 * `AutomationRunnerTest` drives the executor directly; this proves the parts
 * either side of it — that a genuine POST/PATCH raises the right event, that the
 * queued job finds the rule, and that the rule's effect lands on the task.
 *
 * Messenger runs sync in the test environment, so a dispatch executes inline
 * and the effect is visible by the time the request returns.
 */
class AutomationWiringTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $tearDownOrder = [
            'App\Entity\AutomationRun',
            'App\Entity\Automation',
            'App\Entity\Comment',
            'App\Entity\Task',
            'App\Entity\TaskSection',
            'App\Entity\Board',
            'App\Entity\SpaceMembership',
            'App\Entity\Space',
            'App\Entity\User',
        ];
        foreach ($tearDownOrder as $entity) {
            $this->entityManager->createQuery('DELETE FROM ' . $entity)->execute();
        }
    }

    /** Creating a task through the API fires a `task.created` rule. */
    public function testCreatingATaskRunsARule(): void
    {
        [$user, $board] = $this->scaffold();
        $this->makeRule($board, $user, AutomationEvents::TASK_CREATED, 'task.created', 'triaged');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Fresh', 'board' => '/boards/' . $board->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertTagApplied('Fresh', 'triaged');
    }

    /** Completing a task through the API fires a `task.completed` rule. */
    public function testCompletingATaskRunsARule(): void
    {
        [$user, $board] = $this->scaffold();
        $this->makeRule($board, $user, AutomationEvents::TASK_COMPLETED, 'task.completed', 'done-tag');
        $task = $this->makeTask($user, $board, 'Finish me');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => (new \DateTimeImmutable())->format(DATE_ATOM)],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertTagApplied('Finish me', 'done-tag');
    }

    /**
     * A completion must not also look like a generic update — otherwise a rule
     * on "task is updated" would fire twice for one edit.
     */
    public function testCompletionDoesNotAlsoRaiseAnUpdate(): void
    {
        [$user, $board] = $this->scaffold();
        $this->makeRule($board, $user, AutomationEvents::TASK_UPDATED, 'task.moved_to_section', 'moved');
        $task = $this->makeTask($user, $board, 'Just completing');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => (new \DateTimeImmutable())->format(DATE_ATOM)],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(0, $reloaded->getTags());
    }

    /**
     * Deleting a task fires its rule, and a mutating action is refused rather
     * than silently editing a row that no longer exists.
     */
    public function testDeletingATaskRunsARuleButRefusesMutatingActions(): void
    {
        [$user, $board] = $this->scaffold();
        // add_tag has nothing left to tag once the task is gone.
        $this->makeRule($board, $user, AutomationEvents::TASK_DELETED, 'task.deleted', 'ghost-tag');
        $task = $this->makeTask($user, $board, 'Doomed');
        $taskId = (string) $task->getId();

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('DELETE', '/tasks/' . $taskId);
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();

        // The rule ran and was logged — that's the point of the event.
        $runs = $this->entityManager->getRepository(AutomationRun::class)->findAll();
        $this->assertCount(1, $runs);
        $this->assertSame('Doomed', $runs[0]->getTaskTitle());

        // …but the action was skipped with an explanation, not silently
        // "applied" against a detached object nobody saves.
        $actionStep = null;
        foreach ($runs[0]->getSteps() as $step) {
            if ('action' === $step['kind']) {
                $actionStep = $step;
            }
        }
        $this->assertNotNull($actionStep, 'The action should appear in the log even when skipped.');
        $this->assertSame('skipped', $actionStep['outcome']);
        $this->assertStringContainsString('deleted', $actionStep['detail']);

        // And the task really is gone.
        $this->assertNull($this->entityManager->getRepository(Task::class)->find($taskId));
    }

    /** A board with no deletion rule must not enqueue anything. */
    public function testDeletingATaskWithNoRuleLogsNothing(): void
    {
        [$user, $board] = $this->scaffold();
        $task = $this->makeTask($user, $board, 'Unwatched');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('DELETE', '/tasks/' . $task->getId());
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $this->assertCount(0, $this->entityManager->getRepository(AutomationRun::class)->findAll());
    }

    /** A disabled rule must not run. */
    public function testADisabledRuleIsSkipped(): void
    {
        [$user, $board] = $this->scaffold();
        $rule = $this->makeRule($board, $user, AutomationEvents::TASK_CREATED, 'task.created', 'nope');
        $rule->setEnabled(false);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Untouched', 'board' => '/boards/' . $board->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['title' => 'Untouched']);
        $this->assertNotNull($task);
        $this->assertCount(0, $task->getTags());
    }

    /** A rule on another board must not touch this one's tasks. */
    public function testARuleOnAnotherBoardDoesNotFire(): void
    {
        [$user, $board] = $this->scaffold();
        $space = $board->getSpace();
        $this->assertNotNull($space);
        $otherBoard = $this->makeBoard($space, $user, 'Other');
        $this->makeRule($otherBoard, $user, AutomationEvents::TASK_CREATED, 'task.created', 'wrong-board');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Mine', 'board' => '/boards/' . $board->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['title' => 'Mine']);
        $this->assertNotNull($task);
        $this->assertCount(0, $task->getTags());
    }

    /** Every run is recorded, so "why did my task change" is answerable. */
    public function testTheRunIsLogged(): void
    {
        [$user, $board] = $this->scaffold();
        $this->makeRule($board, $user, AutomationEvents::TASK_CREATED, 'task.created', 'logged');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Audited', 'board' => '/boards/' . $board->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $runs = $this->entityManager->getRepository(AutomationRun::class)->findAll();
        $this->assertCount(1, $runs);
        $this->assertSame(AutomationRun::STATUS_APPLIED, $runs[0]->getStatus());
        // The title is snapshotted so the log survives the task's deletion.
        $this->assertSame('Audited', $runs[0]->getTaskTitle());
    }

    private function assertTagApplied(string $taskTitle, string $tag): void
    {
        $this->entityManager->clear();
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['title' => $taskTitle]);
        $this->assertNotNull($task, 'The task should exist.');

        $tags = array_map(static fn ($t) => $t->getTitle(), $task->getTags()->toArray());
        $this->assertContains($tag, $tags, sprintf('Expected the rule to tag "%s".', $tag));
    }

    private function makeRule(
        Board $board,
        User $author,
        string $event,
        string $triggerType,
        string $tag,
    ): Automation {
        $rule = (new Automation())
            ->setBoard($board)
            ->setName('Tag it')
            ->setTriggerEvent($event)
            ->setGraph([
                'nodes' => [
                    ['id' => 't', 'kind' => 'trigger', 'type' => $triggerType, 'config' => []],
                    ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => $tag]],
                ],
                'edges' => [['from' => 't', 'to' => 'a']],
            ])
            ->setCreatedBy($author);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $rule;
    }

    /** @return array{0: User, 1: Board} */
    private function scaffold(): array
    {
        $user = $this->makeUser('owner@example.com');
        $space = (new Space())->setName('Studio')->setCreatedBy($user);
        $this->entityManager->persist($space);
        $space->addUserMembership((new SpaceMembership())->setUser($user)->setRole(Space::ROLE_ADMIN));
        $this->entityManager->flush();

        return [$user, $this->makeBoard($space, $user, 'Main')];
    }

    private function makeBoard(Space $space, User $owner, string $title): Board
    {
        $board = (new Board())->setTitle($title)->setSpace($space)->setOwner($owner);
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
    }

    private function makeTask(User $owner, Board $board, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setBoard($board);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    private function makeUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

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
