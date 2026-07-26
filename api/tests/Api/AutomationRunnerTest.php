<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Automation\AutomationContext;
use App\Automation\AutomationEvents;
use App\Automation\AutomationRunner;
use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Task;
use App\Entity\TaskSection;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Executing a rule graph against a real change (#764).
 *
 * The two that matter most: the **depth limit**, which is the only thing
 * standing between two rules that edit each other and a wedged worker, and the
 * **space confinement** on actions, which is the only thing stopping a rule
 * assigning work to someone outside the space.
 */
class AutomationRunnerTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;
    private AutomationRunner $runner;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->runner = static::getContainer()->get(AutomationRunner::class);

        // Child-first, so the FKs come apart cleanly.
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

    public function testRunsActionsUnderAPassingCondition(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board, 'Ship it');

        $rule = $this->makeRule($board, $owner, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'shipped']],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ]);

        $run = $this->runner->run($rule, $this->context($task, AutomationEvents::TASK_COMPLETED));

        $this->assertNotNull($run);
        // Include the step details: a bare "expected applied, got failed" says
        // nothing about which action broke or why.
        $this->assertSame(
            AutomationRun::STATUS_APPLIED,
            $run->getStatus(),
            json_encode($run->getSteps(), JSON_THROW_ON_ERROR),
        );

        $names = array_map(static fn ($t) => $t->getTitle(), $task->getTags()->toArray());
        $this->assertContains('shipped', $names);
    }

    public function testAFailedConditionGatesItsBranch(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board, 'Untagged');

        $rule = $this->makeRule($board, $owner, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'c', 'kind' => 'condition', 'type' => 'task.has_tag', 'config' => ['tag' => 'urgent']],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'escalated']],
            ],
            'edges' => [['from' => 't', 'to' => 'c'], ['from' => 'c', 'to' => 'a']],
        ]);

        $run = $this->runner->run($rule, $this->context($task, AutomationEvents::TASK_COMPLETED));

        $this->assertNotNull($run);
        // Fired, but nothing downstream ran — distinct from "applied", because
        // this is the usual answer to "why didn't my rule work".
        $this->assertSame(AutomationRun::STATUS_NO_MATCH, $run->getStatus());
        $this->assertCount(0, $task->getTags());
    }

    /**
     * The defence that no per-graph check can provide: rule A edits a task,
     * which fires rule B, which edits it back. Past the depth limit, a rule may
     * still evaluate but may not change anything.
     */
    public function testActionsStopOnceTheDepthLimitIsReached(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board, 'Ping-pong');

        $rule = $this->makeRule($board, $owner, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'looped']],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ]);

        $deep = new AutomationContext(
            task: $task,
            event: AutomationEvents::TASK_COMPLETED,
            depth: AutomationContext::MAX_DEPTH,
        );

        $run = $this->runner->run($rule, $deep);

        $this->assertNotNull($run);
        $this->assertCount(0, $task->getTags(), 'A rule past the depth limit must not mutate.');

        $steps = $run->getSteps();
        $this->assertSame('skipped', $steps[0]['outcome']);
        $this->assertStringContainsString('automation', $steps[0]['detail']);
    }

    /** A rule must not be able to assign work to someone outside its space. */
    public function testAssigningOutsideTheSpaceFailsClosed(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $outsider = $this->makeUser('outsider@example.com');
        $task = $this->makeTask($owner, $board, 'Confined');

        $rule = $this->makeRule($board, $owner, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                [
                    'id' => 'a',
                    'kind' => 'action',
                    'type' => 'task.assign',
                    'config' => ['user' => (string) $outsider->getId()],
                ],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ]);

        $run = $this->runner->run($rule, $this->context($task, AutomationEvents::TASK_COMPLETED));

        $this->assertNotNull($run);
        $this->assertSame(AutomationRun::STATUS_FAILED, $run->getStatus());
        $this->assertCount(0, $task->getAssignees());
        $this->assertStringContainsString('not a member', $run->getSteps()[0]['detail']);
    }

    /** A section on another board is the same class of leak as a foreign user. */
    public function testMovingToAForeignSectionFailsClosed(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $otherBoard = $this->makeBoard($space, $owner, 'Other board');
        $foreign = $this->makeSection($otherBoard, 'Elsewhere');
        $task = $this->makeTask($owner, $board, 'Stay put');

        $rule = $this->makeRule($board, $owner, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                [
                    'id' => 'a',
                    'kind' => 'action',
                    'type' => 'task.move_section',
                    'config' => ['section' => (string) $foreign->getId()],
                ],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ]);

        $run = $this->runner->run($rule, $this->context($task, AutomationEvents::TASK_COMPLETED));

        $this->assertNotNull($run);
        $this->assertSame(AutomationRun::STATUS_FAILED, $run->getStatus());
        $this->assertNull($task->getSection());
        $this->assertStringContainsString('different board', $run->getSteps()[0]['detail']);
    }

    /** One bad action must not discard its siblings' work. */
    public function testAFailingActionDoesNotStopItsSiblings(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board, 'Partial');

        $rule = $this->makeRule($board, $owner, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                [
                    'id' => 'bad',
                    'kind' => 'action',
                    'type' => 'task.move_section',
                    'config' => ['section' => '00000000-0000-0000-0000-000000000000'],
                ],
                ['id' => 'good', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'survived']],
            ],
            'edges' => [['from' => 't', 'to' => 'bad'], ['from' => 't', 'to' => 'good']],
        ]);

        $run = $this->runner->run($rule, $this->context($task, AutomationEvents::TASK_COMPLETED));

        $this->assertNotNull($run);
        $this->assertSame(AutomationRun::STATUS_FAILED, $run->getStatus());

        $names = array_map(static fn ($t) => $t->getTitle(), $task->getTags()->toArray());
        $this->assertContains('survived', $names, 'A sibling action should still have run.');
    }

    public function testDoesNotRunWhenTheEventDoesNotMatch(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board, 'Quiet');

        $rule = $this->makeRule($board, $owner, [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'nope']],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ]);

        // Null rather than an empty run: the log holds rules that fired, not a
        // row per rule per edit.
        $this->assertNull($this->runner->run($rule, $this->context($task, AutomationEvents::TASK_CREATED)));
    }

    /** A stored graph the server can no longer parse disables itself. */
    public function testAnUnparseableGraphDisablesTheRule(): void
    {
        [$owner, $space, $board] = $this->scaffold();
        $task = $this->makeTask($owner, $board, 'Broken');

        $rule = $this->makeRule($board, $owner, ['nodes' => 'not-a-list', 'edges' => []]);

        $this->assertNull($this->runner->run($rule, $this->context($task, AutomationEvents::TASK_COMPLETED)));
        $this->assertFalse($rule->isEnabled());
        $this->assertNotNull($rule->getLastError());
    }

    private function context(Task $task, string $event): AutomationContext
    {
        return new AutomationContext(task: $task, event: $event);
    }

    /** @param array<string, mixed> $graph */
    private function makeRule(Board $board, User $author, array $graph): Automation
    {
        $rule = (new Automation())
            ->setBoard($board)
            ->setName('Test rule')
            ->setTriggerEvent(AutomationEvents::TASK_COMPLETED)
            ->setGraph($graph)
            ->setCreatedBy($author);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $rule;
    }

    /** @return array{0: User, 1: Space, 2: Board} */
    private function scaffold(): array
    {
        $owner = $this->makeUser('owner@example.com');
        $space = (new Space())->setName('Studio')->setCreatedBy($owner);
        $this->entityManager->persist($space);
        $space->addUserMembership((new SpaceMembership())->setUser($owner)->setRole(Space::ROLE_ADMIN));
        $this->entityManager->flush();

        return [$owner, $space, $this->makeBoard($space, $owner, 'Main')];
    }

    private function makeBoard(Space $space, User $owner, string $title): Board
    {
        $board = (new Board())->setTitle($title)->setSpace($space)->setOwner($owner);
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
    }

    private function makeSection(Board $board, string $title): TaskSection
    {
        $section = (new TaskSection())->setBoard($board)->setTitle($title);
        $section->syncSpaceFromBoard();
        $this->entityManager->persist($section);
        $this->entityManager->flush();

        return $section;
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
