<?php

declare(strict_types=1);

namespace App\Tests\Automation;

use App\Automation\AutomationGraph;
use PHPUnit\Framework\TestCase;

/**
 * Graph parsing and validation (#764).
 *
 * This is the gate every rule passes through before it can ever run, so the
 * rejections matter more than the happy path — particularly the cycle check,
 * which is what stops a rule running until the worker dies.
 */
class AutomationGraphTest extends TestCase
{
    public function testParsesAValidGraph(): void
    {
        $graph = AutomationGraph::fromArray($this->valid());

        $this->assertSame('task.completed', $graph->triggerType());
        $this->assertSame(['cond'], $graph->childrenOf('trig'));
        $this->assertSame(['act'], $graph->childrenOf('cond'));
        $this->assertCount(3, $graph->allNodes());
    }

    public function testRejectsACycle(): void
    {
        // act loops back to cond — this would run forever.
        $raw = $this->valid();
        $raw['edges'][] = ['from' => 'act', 'to' => 'cond'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('loops back on itself');
        AutomationGraph::fromArray($raw);
    }

    public function testRejectsALongerCycle(): void
    {
        // A three-step ring — the case a naive "does an edge point backwards"
        // check would miss.
        $raw = [
            'nodes' => [
                ['id' => 'trig', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.assign', 'config' => []],
                ['id' => 'b', 'kind' => 'action', 'type' => 'task.assign', 'config' => []],
                ['id' => 'c', 'kind' => 'action', 'type' => 'task.assign', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trig', 'to' => 'a'],
                ['from' => 'a', 'to' => 'b'],
                ['from' => 'b', 'to' => 'c'],
                ['from' => 'c', 'to' => 'a'],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        AutomationGraph::fromArray($raw);
    }

    public function testRejectsSelfEdge(): void
    {
        $raw = $this->valid();
        $raw['edges'][] = ['from' => 'act', 'to' => 'act'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot connect to itself');
        AutomationGraph::fromArray($raw);
    }

    public function testRequiresExactlyOneTrigger(): void
    {
        $none = $this->valid();
        $none['nodes'] = array_values(array_filter(
            $none['nodes'],
            static fn (array $n): bool => 'trigger' !== $n['kind'],
        ));
        $none['edges'] = [];

        try {
            AutomationGraph::fromArray($none);
            $this->fail('A graph with no trigger must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a trigger', $e->getMessage());
        }

        $two = $this->valid();
        $two['nodes'][] = ['id' => 'trig2', 'kind' => 'trigger', 'type' => 'task.created', 'config' => []];
        $two['edges'][] = ['from' => 'trig2', 'to' => 'cond'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only have one trigger');
        AutomationGraph::fromArray($two);
    }

    public function testRejectsAnEdgeIntoTheTrigger(): void
    {
        $raw = $this->valid();
        $raw['edges'][] = ['from' => 'act', 'to' => 'trig'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('starts the rule');
        AutomationGraph::fromArray($raw);
    }

    public function testRejectsOrphanedSteps(): void
    {
        // A node with no path from the trigger is almost always a half-finished
        // edit; silently never running it reads as "automations are broken".
        $raw = $this->valid();
        $raw['nodes'][] = ['id' => 'lost', 'kind' => 'action', 'type' => 'task.assign', 'config' => []];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('floating loose');
        AutomationGraph::fromArray($raw);
    }

    public function testRejectsDuplicateIdsAndUnknownReferences(): void
    {
        $dupe = $this->valid();
        $dupe['nodes'][] = ['id' => 'act', 'kind' => 'action', 'type' => 'task.assign', 'config' => []];

        try {
            AutomationGraph::fromArray($dupe);
            $this->fail('Duplicate ids must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Duplicate step id', $e->getMessage());
        }

        $dangling = $this->valid();
        $dangling['edges'][] = ['from' => 'act', 'to' => 'nope'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        AutomationGraph::fromArray($dangling);
    }

    public function testRejectsAnUnknownKind(): void
    {
        $raw = $this->valid();
        $raw['nodes'][1]['kind'] = 'sorcery';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown kind');
        AutomationGraph::fromArray($raw);
    }

    public function testRejectsAnOversizedGraph(): void
    {
        $nodes = [['id' => 'trig', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []]];
        $edges = [];
        for ($i = 0; $i < AutomationGraph::MAX_NODES + 1; ++$i) {
            $nodes[] = ['id' => "a{$i}", 'kind' => 'action', 'type' => 'task.assign', 'config' => []];
            $edges[] = ['from' => 'trig', 'to' => "a{$i}"];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most');
        AutomationGraph::fromArray(['nodes' => $nodes, 'edges' => $edges]);
    }

    /** Fan-out is the whole reason for a canvas, so it must survive parsing. */
    public function testSupportsBranching(): void
    {
        $raw = [
            'nodes' => [
                ['id' => 'trig', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'a', 'kind' => 'action', 'type' => 'task.assign', 'config' => []],
                ['id' => 'b', 'kind' => 'action', 'type' => 'task.comment', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trig', 'to' => 'a'],
                ['from' => 'trig', 'to' => 'b'],
            ],
        ];

        $graph = AutomationGraph::fromArray($raw);
        // Order is preserved: actions run in the order their edges were drawn.
        $this->assertSame(['a', 'b'], $graph->childrenOf('trig'));
    }

    /**
     * A diamond is not a cycle. The visitor has to remember finished nodes, or
     * re-reaching one down a second path looks like a loop.
     */
    public function testDiamondsAreNotCycles(): void
    {
        $raw = [
            'nodes' => [
                ['id' => 'trig', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'l', 'kind' => 'condition', 'type' => 'task.has_tag', 'config' => []],
                ['id' => 'r', 'kind' => 'condition', 'type' => 'task.has_tag', 'config' => []],
                ['id' => 'end', 'kind' => 'action', 'type' => 'task.assign', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trig', 'to' => 'l'],
                ['from' => 'trig', 'to' => 'r'],
                ['from' => 'l', 'to' => 'end'],
                ['from' => 'r', 'to' => 'end'],
            ],
        ];

        $graph = AutomationGraph::fromArray($raw);
        $this->assertCount(4, $graph->allNodes());
    }

    /**
     * @return array{
     *     nodes: list<array{id: string, kind: string, type: string, config: array<string, mixed>}>,
     *     edges: list<array{from: string, to: string}>
     * }
     */
    private function valid(): array
    {
        return [
            'nodes' => [
                ['id' => 'trig', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                ['id' => 'cond', 'kind' => 'condition', 'type' => 'task.has_tag', 'config' => ['tag' => 'urgent']],
                ['id' => 'act', 'kind' => 'action', 'type' => 'task.assign', 'config' => ['user' => 'someone']],
            ],
            'edges' => [
                ['from' => 'trig', 'to' => 'cond'],
                ['from' => 'cond', 'to' => 'act'],
            ],
        ];
    }
}
