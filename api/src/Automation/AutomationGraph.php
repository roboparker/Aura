<?php

declare(strict_types=1);

namespace App\Automation;

/**
 * A parsed, validated automation graph (#764).
 *
 * The stored shape is `{nodes: [{id, kind, type, config}], edges: [{from, to}]}`.
 * This class is the only thing that reads it, so the rest of the engine never
 * touches raw arrays and can't disagree about what a valid graph is.
 *
 * Semantics, deliberately small:
 *
 * - Exactly **one trigger**, and it is the root. A graph with none can never
 *   fire; a graph with two is ambiguous about which change started it.
 * - A **condition is a gate**: its downstream subtree runs only when it passes.
 *   There is no true/false pair of outputs — for an either/or, hang two
 *   conditions off the same parent. That keeps "the branch under a failed
 *   condition didn't run" easy to reason about when a rule misbehaves.
 * - Edges are directed, and **cycles are rejected**. A rule that can reach
 *   itself would run until the worker died.
 */
final class AutomationGraph
{
    public const KIND_TRIGGER = 'trigger';
    public const KIND_CONDITION = 'condition';
    public const KIND_ACTION = 'action';

    public const KINDS = [self::KIND_TRIGGER, self::KIND_CONDITION, self::KIND_ACTION];

    /** Guards against a pathological graph being saved at all. */
    public const MAX_NODES = 60;

    /**
     * @param array<string, array{id: string, kind: string, type: string, config: array<string, mixed>}> $nodes keyed by id
     * @param array<string, list<string>>                                                                $edges from-id => list of to-ids, in order
     */
    private function __construct(
        private array $nodes,
        private array $edges,
        private string $triggerId,
    ) {
    }

    /**
     * Parses and validates a stored graph.
     *
     * @param array<string, mixed> $raw
     *
     * @throws \InvalidArgumentException with a message meant for the user
     */
    public static function fromArray(array $raw): self
    {
        $rawNodes = $raw['nodes'] ?? null;
        $rawEdges = $raw['edges'] ?? [];
        if (!is_array($rawNodes) || !is_array($rawEdges)) {
            throw new \InvalidArgumentException('A rule needs a list of nodes and edges.');
        }
        if (count($rawNodes) > self::MAX_NODES) {
            throw new \InvalidArgumentException(sprintf('A rule can have at most %d steps.', self::MAX_NODES));
        }

        $nodes = [];
        $triggerId = null;
        foreach ($rawNodes as $node) {
            if (!is_array($node)) {
                throw new \InvalidArgumentException('Every step must be an object.');
            }

            $id = $node['id'] ?? null;
            $kind = $node['kind'] ?? null;
            $type = $node['type'] ?? null;
            if (!is_string($id) || '' === $id) {
                throw new \InvalidArgumentException('Every step needs an id.');
            }
            if (isset($nodes[$id])) {
                throw new \InvalidArgumentException(sprintf('Duplicate step id "%s".', $id));
            }
            if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException(sprintf('Step "%s" has an unknown kind.', $id));
            }
            if (!is_string($type) || '' === $type) {
                throw new \InvalidArgumentException(sprintf('Step "%s" needs a type.', $id));
            }

            $config = $node['config'] ?? [];
            if (!is_array($config)) {
                throw new \InvalidArgumentException(sprintf('Step "%s" has an invalid configuration.', $id));
            }

            if (self::KIND_TRIGGER === $kind) {
                if (null !== $triggerId) {
                    throw new \InvalidArgumentException('A rule can only have one trigger.');
                }
                $triggerId = $id;
            }

            /** @var array<string, mixed> $config */
            $nodes[$id] = ['id' => $id, 'kind' => $kind, 'type' => $type, 'config' => $config];
        }

        if (null === $triggerId) {
            throw new \InvalidArgumentException('A rule needs a trigger to start it.');
        }

        $edges = [];
        foreach ($rawEdges as $edge) {
            if (!is_array($edge)) {
                throw new \InvalidArgumentException('Every connection must be an object.');
            }
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;
            if (!is_string($from) || !is_string($to)) {
                throw new \InvalidArgumentException('Every connection needs a from and a to.');
            }
            if (!isset($nodes[$from]) || !isset($nodes[$to])) {
                throw new \InvalidArgumentException('A connection points at a step that does not exist.');
            }
            if ($from === $to) {
                throw new \InvalidArgumentException('A step cannot connect to itself.');
            }
            if (self::KIND_TRIGGER === $nodes[$to]['kind']) {
                throw new \InvalidArgumentException('Nothing can lead into the trigger — it starts the rule.');
            }

            $edges[$from][] = $to;
        }

        $graph = new self($nodes, $edges, $triggerId);
        $graph->assertAcyclic();
        $graph->assertReachable();

        return $graph;
    }

    /**
     * Depth-first cycle check.
     *
     * The load-bearing validation: a cycle inside one rule would run forever.
     * (Loops *between* rules are a different problem, handled by the execution
     * depth limit — no per-graph check can see them.)
     */
    private function assertAcyclic(): void
    {
        $state = [];

        $visit = function (string $id) use (&$visit, &$state): void {
            if ('visiting' === ($state[$id] ?? null)) {
                throw new \InvalidArgumentException('This rule loops back on itself.');
            }
            if ('done' === ($state[$id] ?? null)) {
                return;
            }

            $state[$id] = 'visiting';
            foreach ($this->edges[$id] ?? [] as $next) {
                $visit($next);
            }
            $state[$id] = 'done';
        };

        foreach (array_keys($this->nodes) as $id) {
            $visit($id);
        }
    }

    /**
     * Every step must be reachable from the trigger.
     *
     * An orphaned branch is almost always a half-finished edit, and silently
     * never running it is the kind of thing that gets reported as "automations
     * are broken" months later.
     */
    private function assertReachable(): void
    {
        $seen = [];
        $stack = [$this->triggerId];
        while ([] !== $stack) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($this->edges[$id] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        $orphans = array_diff(array_keys($this->nodes), array_keys($seen));
        if ([] !== $orphans) {
            throw new \InvalidArgumentException(
                'Every step must connect back to the trigger — some are floating loose.',
            );
        }
    }

    /** @return array{id: string, kind: string, type: string, config: array<string, mixed>} */
    public function trigger(): array
    {
        return $this->nodes[$this->triggerId];
    }

    public function triggerType(): string
    {
        return $this->nodes[$this->triggerId]['type'];
    }

    /** @return array{id: string, kind: string, type: string, config: array<string, mixed>}|null */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * Ids downstream of `$id`, in the order their edges were declared.
     *
     * @return list<string>
     */
    public function childrenOf(string $id): array
    {
        return $this->edges[$id] ?? [];
    }

    /** @return list<array{id: string, kind: string, type: string, config: array<string, mixed>}> */
    public function allNodes(): array
    {
        return array_values($this->nodes);
    }
}
