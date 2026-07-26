<?php

declare(strict_types=1);

namespace App\Automation;

/**
 * Checks a submitted graph against what this server can actually run (#764).
 *
 * {@see AutomationGraph} validates *shape* — one trigger, no cycles, nothing
 * orphaned. This validates *meaning*: that every node type is registered and
 * every config is one that type accepts.
 *
 * Deliberately separate. Shape is a property of the graph and can be checked
 * without a container; meaning depends on which strategies are installed, and a
 * server missing a plugin should reject the rule rather than store one it can
 * never run.
 */
final class AutomationValidator
{
    public function __construct(
        private AutomationRegistry $registry,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{graph: AutomationGraph, event: string}
     *
     * @throws \InvalidArgumentException with a message meant for the user
     */
    public function validate(array $raw): array
    {
        // Shape first: a cyclic graph isn't worth type-checking.
        $graph = AutomationGraph::fromArray($raw);

        foreach ($graph->allNodes() as $node) {
            $error = $this->checkNode($node);
            if (null !== $error) {
                throw new \InvalidArgumentException($error);
            }
        }

        $trigger = $this->registry->trigger($graph->triggerType());
        // checkNode already proved this resolves; re-narrow for the type system.
        if (null === $trigger) {
            throw new \InvalidArgumentException('That trigger is not available on this server.');
        }

        return ['graph' => $graph, 'event' => $trigger->event()];
    }

    /**
     * @param array{id: string, kind: string, type: string, config: array<string, mixed>} $node
     */
    private function checkNode(array $node): ?string
    {
        $definition = match ($node['kind']) {
            AutomationGraph::KIND_TRIGGER => $this->registry->trigger($node['type']),
            AutomationGraph::KIND_CONDITION => $this->registry->condition($node['type']),
            default => $this->registry->action($node['type']),
        };

        if (null === $definition) {
            return sprintf('"%s" is not a step this server knows about.', $node['type']);
        }

        $problem = $definition->validateConfig($node['config']);

        // Prefixed with the step's own label, so a multi-step rule says which
        // one is wrong rather than just "invalid".
        return null === $problem ? null : sprintf('%s: %s', $definition->label(), $problem);
    }
}
