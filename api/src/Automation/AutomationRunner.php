<?php

declare(strict_types=1);

namespace App\Automation;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Walks one rule's graph against one change (#764).
 *
 * Depth-first from the trigger: a condition gates its subtree, an action runs
 * and records what it did. Every step lands in an {@see AutomationRun} — a
 * rules engine without a run log is unsupportable, because "why didn't my rule
 * fire" has no other answer.
 *
 * **Failure is per-step, not per-rule.** One action throwing shouldn't discard
 * the ones that already succeeded, so the error is recorded against that step
 * and the walk continues with its siblings. The alternative — rolling
 * everything back — reads worse in practice: a rule that assigns and comments
 * shouldn't lose the assignment because a tag was deleted.
 */
final class AutomationRunner
{
    /**
     * Ceiling on steps executed in one run. A graph is capped at 60 nodes, but
     * fan-out means a walk can visit far more than that.
     */
    private const MAX_STEPS = 200;

    public function __construct(
        private AutomationRegistry $registry,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Runs `$automation` against `$context`, or returns null if it doesn't apply.
     *
     * Returns null — rather than an empty run — when the trigger doesn't match,
     * so the log holds rules that actually fired instead of a row per rule per
     * task edit.
     */
    public function run(Automation $automation, AutomationContext $context): ?AutomationRun
    {
        try {
            $graph = AutomationGraph::fromArray($automation->getGraph());
        } catch (\InvalidArgumentException $e) {
            // A stored graph that no longer parses — a node type was removed
            // from the server under it. Disable rather than fail every edit.
            $this->logger->warning('Automation graph is no longer valid; disabling.', [
                'automation' => (string) $automation->getId(),
                'error' => $e->getMessage(),
            ]);
            $automation->setEnabled(false);
            $automation->setLastError($e->getMessage());

            return null;
        }

        $triggerNode = $graph->trigger();
        $trigger = $this->registry->trigger($triggerNode['type']);
        if (null === $trigger || $trigger->event() !== $context->event) {
            return null;
        }
        if (!$trigger->matches($context, $triggerNode['config'])) {
            return null;
        }

        $run = (new AutomationRun())
            ->setAutomation($automation)
            ->setTask($context->task)
            ->setDepth($context->depth);

        $steps = [];
        $budget = self::MAX_STEPS;
        foreach ($graph->childrenOf($triggerNode['id']) as $childId) {
            $this->walk($graph, $childId, $context, $steps, $budget);
        }

        $run->setSteps($steps);
        $run->setStatus($this->outcomeOf($steps));
        $this->em->persist($run);

        $automation->setLastRunAt(new \DateTimeImmutable());

        return $run;
    }

    /**
     * @param list<array{node: string, kind: string, type: string, outcome: string, detail: string}> $steps
     */
    private function walk(
        AutomationGraph $graph,
        string $nodeId,
        AutomationContext $context,
        array &$steps,
        int &$budget,
    ): void {
        if ($budget-- <= 0) {
            return;
        }

        $node = $graph->node($nodeId);
        if (null === $node) {
            return;
        }

        if (AutomationGraph::KIND_CONDITION === $node['kind']) {
            $condition = $this->registry->condition($node['type']);
            if (null === $condition) {
                $steps[] = $this->step($node, 'error', 'This condition is not available on this server.');

                return;
            }

            try {
                $passed = $condition->passes($context, $node['config']);
            } catch (\Throwable $e) {
                $steps[] = $this->step($node, 'error', $e->getMessage());

                return;
            }

            $steps[] = $this->step($node, $passed ? 'passed' : 'skipped', $condition->label());
            if (!$passed) {
                // The gate is shut: nothing downstream runs.
                return;
            }
        } elseif (AutomationGraph::KIND_ACTION === $node['kind']) {
            $action = $this->registry->action($node['type']);
            if (null === $action) {
                $steps[] = $this->step($node, 'error', 'This action is not available on this server.');

                return;
            }

            // The depth limit bites here, at the point of mutation: a rule
            // beyond it may still evaluate, but it may not change anything.
            // That's what stops rule A and rule B editing each other forever.
            if (!$context->withinDepthLimit()) {
                $steps[] = $this->step(
                    $node,
                    'skipped',
                    'Stopped — this change was already made by an automation.',
                );

                return;
            }

            try {
                $detail = $action->execute($context, $node['config']);
                $steps[] = $this->step($node, 'done', $detail);
            } catch (\Throwable $e) {
                // Recorded and stepped over: siblings that would have succeeded
                // shouldn't be lost to one bad target.
                $steps[] = $this->step($node, 'error', $e->getMessage());

                return;
            }
        }

        foreach ($graph->childrenOf($nodeId) as $childId) {
            $this->walk($graph, $childId, $context, $steps, $budget);
        }
    }

    /**
     * @param array{id: string, kind: string, type: string, config: array<string, mixed>} $node
     *
     * @return array{node: string, kind: string, type: string, outcome: string, detail: string}
     */
    private function step(array $node, string $outcome, string $detail): array
    {
        return [
            'node' => $node['id'],
            'kind' => $node['kind'],
            'type' => $node['type'],
            'outcome' => $outcome,
            'detail' => $detail,
        ];
    }

    /**
     * @param list<array{node: string, kind: string, type: string, outcome: string, detail: string}> $steps
     */
    private function outcomeOf(array $steps): string
    {
        foreach ($steps as $step) {
            if ('error' === $step['outcome']) {
                return AutomationRun::STATUS_FAILED;
            }
        }

        foreach ($steps as $step) {
            if ('done' === $step['outcome']) {
                return AutomationRun::STATUS_APPLIED;
            }
        }

        // Fired, but every branch was gated off — worth distinguishing from
        // "applied", because it's the usual answer to "why didn't it work".
        return AutomationRun::STATUS_NO_MATCH;
    }
}
