<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Automation\AutomationEvents;
use App\Entity\Automation;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The rule CRUD the canvas saves against (#764).
 *
 * Two things carry weight: only space admins can write a rule (it mutates other
 * people's tasks), and a graph is type-checked against the registry before it
 * is ever stored — a rule that can't run is worse than no rule, because it
 * looks like it works.
 */
class AutomationCrudTest extends ApiTestCase
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
            'App\Entity\Task',
            'App\Entity\Board',
            'App\Entity\SpaceMembership',
            'App\Entity\Space',
            'App\Entity\User',
        ];
        foreach ($tearDownOrder as $entity) {
            $this->entityManager->createQuery('DELETE FROM ' . $entity)->execute();
        }
    }

    public function testAdminCreatesAndListsARule(): void
    {
        [$admin, $member, $board] = $this->scaffold();

        $client = static::createClient();
        $client->loginUser($admin);

        $created = $client->request('POST', '/boards/' . $board->getId() . '/automations', [
            'json' => ['name' => 'Tag on done', 'graph' => $this->validGraph()],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Tag on done', $this->stringField($created, 'name'));
        // The event is derived from the trigger, not sent by the client.
        $this->assertSame(AutomationEvents::TASK_COMPLETED, $this->stringField($created, 'triggerEvent'));

        $list = $client->request('GET', '/boards/' . $board->getId() . '/automations')->toArray();
        $this->assertTrue($this->boolField($list, 'canEdit'));
        $this->assertCount(1, $this->arrayField($list, 'automations'));

        // A plain member can read the board's rules but not edit them.
        $client->loginUser($member);
        $memberList = $client->request('GET', '/boards/' . $board->getId() . '/automations')->toArray();
        $this->assertFalse($this->boolField($memberList, 'canEdit'));
        $this->assertCount(1, $this->arrayField($memberList, 'automations'));
    }

    public function testMembersCannotWriteRules(): void
    {
        [$admin, $member, $board] = $this->scaffold();
        $rule = $this->makeRule($board, $admin);

        $client = static::createClient();
        $client->loginUser($member);

        // A rule changes other people's tasks — that's an admin decision.
        $client->request('POST', '/boards/' . $board->getId() . '/automations', [
            'json' => ['name' => 'Mine', 'graph' => $this->validGraph()],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('PATCH', '/automations/' . $rule->getId(), [
            'json' => ['enabled' => false],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/automations/' . $rule->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testOutsidersGetTheExistenceHiding404(): void
    {
        [$admin, $member, $board] = $this->scaffold();
        $rule = $this->makeRule($board, $admin);
        $outsider = $this->makeUser('outsider@example.com');

        $client = static::createClient();
        $client->loginUser($outsider);

        $client->request('GET', '/boards/' . $board->getId() . '/automations');
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/automations/' . $rule->getId() . '/runs');
        $this->assertResponseStatusCodeSame(404);
    }

    /** A rule that can't run is worse than none — it looks like it works. */
    public function testARuleWithAnUnknownStepIsRejected(): void
    {
        [$admin, , $board] = $this->scaffold();

        $client = static::createClient();
        $client->loginUser($admin);

        $graph = $this->validGraph();
        $graph['nodes'][1]['type'] = 'task.launch_missiles';

        $body = $client->request('POST', '/boards/' . $board->getId() . '/automations', [
            'json' => ['name' => 'Bad', 'graph' => $graph],
        ])->toArray(false);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('not a step this server knows about', $this->stringField($body, 'error'));
    }

    public function testABadConfigIsRejectedWithTheStepNamed(): void
    {
        [$admin, , $board] = $this->scaffold();

        $client = static::createClient();
        $client->loginUser($admin);

        $graph = $this->validGraph();
        $graph['nodes'][1]['config'] = ['tag' => ''];

        $body = $client->request('POST', '/boards/' . $board->getId() . '/automations', [
            'json' => ['name' => 'Bad config', 'graph' => $graph],
        ])->toArray(false);

        $this->assertResponseStatusCodeSame(422);
        // Named, so a multi-step rule says which step is wrong.
        $this->assertStringContainsString('Add a tag', $this->stringField($body, 'error'));
    }

    public function testACyclicGraphIsRejected(): void
    {
        [$admin, , $board] = $this->scaffold();

        $client = static::createClient();
        $client->loginUser($admin);

        $graph = $this->validGraph();
        $graph['edges'][] = ['from' => 'a', 'to' => 'a'];

        $client->request('POST', '/boards/' . $board->getId() . '/automations', [
            'json' => ['name' => 'Loop', 'graph' => $graph],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    /** Swapping the trigger must move the denormalised event with it. */
    public function testEditingTheGraphUpdatesTheTriggerEvent(): void
    {
        [$admin, , $board] = $this->scaffold();
        $rule = $this->makeRule($board, $admin);

        $client = static::createClient();
        $client->loginUser($admin);

        $graph = $this->validGraph();
        $graph['nodes'][0]['type'] = 'task.created';

        $body = $client->request('PATCH', '/automations/' . $rule->getId(), [
            'json' => ['graph' => $graph],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();

        $this->assertSame(AutomationEvents::TASK_CREATED, $this->stringField($body, 'triggerEvent'));
    }

    public function testTheCatalogListsEveryRegisteredStep(): void
    {
        [$admin, , $board] = $this->scaffold();

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/boards/' . $board->getId() . '/automations/catalog')->toArray();

        // The canvas builds its palette from this, so it must never be empty.
        $this->assertNotEmpty($this->arrayField($body, 'triggers'));
        $this->assertNotEmpty($this->arrayField($body, 'conditions'));
        $this->assertNotEmpty($this->arrayField($body, 'actions'));
    }

    public function testDeleteRemovesTheRule(): void
    {
        [$admin, , $board] = $this->scaffold();
        $rule = $this->makeRule($board, $admin);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('DELETE', '/automations/' . $rule->getId());
        $this->assertResponseStatusCodeSame(204);

        $list = $client->request('GET', '/boards/' . $board->getId() . '/automations')->toArray();
        $this->assertCount(0, $this->arrayField($list, 'automations'));
    }

    /**
     * @return array{
     *     nodes: list<array{id: string, kind: string, type: string, config: array<string, mixed>}>,
     *     edges: list<array{from: string, to: string}>
     * }
     */
    private function validGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'done']],
            ],
            'edges' => [['from' => 't', 'to' => 'a']],
        ];
    }

    private function makeRule(Board $board, User $author): Automation
    {
        $rule = (new Automation())
            ->setBoard($board)
            ->setName('Existing')
            ->setTriggerEvent(AutomationEvents::TASK_COMPLETED)
            ->setGraph($this->validGraph())
            ->setCreatedBy($author);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $rule;
    }

    /** @return array{0: User, 1: User, 2: Board} */
    private function scaffold(): array
    {
        $admin = $this->makeUser('admin@example.com');
        $member = $this->makeUser('member@example.com');

        $space = (new Space())->setName('Studio')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $space->addUserMembership((new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN));
        $space->addUserMembership((new SpaceMembership())->setUser($member)->setRole(Space::ROLE_MEMBER));
        $this->entityManager->flush();

        $board = (new Board())->setTitle('Main')->setSpace($space)->setOwner($admin);
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return [$admin, $member, $board];
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
