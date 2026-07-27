<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Automation;
use App\Entity\Board;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Repository\AutomationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lists a board's automation rules, flattened into readable when/if/then form.
 *
 * **Read-only, deliberately.** There is no create_automation companion: a rule
 * edits *other people's* tasks on a schedule nobody is watching, which is a far
 * bigger grant than the per-item writes the other MCP tools make. Letting a
 * model author one would hand it durable authority over a board rather than a
 * single reversible action. Writing rules stays a space admin in the UI.
 *
 * The value here is explanatory — "why did this task move?" is answerable by
 * reading the rules plus {@see GetAutomationRunsTool}, and that's exactly the
 * question a model gets asked.
 */
final class ListAutomationsTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private AutomationRepository $automations,
        private McpAuthorization $authz,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_automations';
    }

    public function getDescription(): string
    {
        return 'List the automation rules on a board, each summarised as when/if/then. Read-only. Use with get_automation_runs to explain why a task changed on its own.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'boardId' => ['type' => 'string', 'description' => 'The board whose rules to list.'],
            ],
            'required' => ['boardId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $boardId = $this->input->requireUuid('boardId', $arguments['boardId'] ?? null);
        $board = $this->em->getRepository(Board::class)->find($boardId);

        // Existence-hiding, same as every other board-scoped surface.
        if (!$board instanceof Board || !$this->authz->canReadBoard($board, $user)) {
            throw McpException::notFound('No board with that id.');
        }

        $rules = $this->automations->findBy(['board' => $board], ['createdAt' => 'ASC']);

        return [
            'items' => array_map(
                fn (Automation $rule) => [
                    'id' => (string) $rule->getId(),
                    'name' => $rule->getName(),
                    'enabled' => $rule->isEnabled(),
                    'trigger' => $rule->getTriggerEvent(),
                    'summary' => $this->summarise($rule),
                    // Surfaced because a rule the server switched off after
                    // repeated failures looks identical to one someone paused.
                    'lastError' => $rule->getLastError(),
                    'lastRunAt' => $rule->getLastRunAt()?->format(\DATE_ATOM),
                ],
                $rules,
            ),
        ];
    }

    /**
     * Flattens the graph into "when X, only if Y, then Z" — a shape a model can
     * actually reason about, rather than raw nodes and edges.
     */
    private function summarise(Automation $rule): string
    {
        $graph = $rule->getGraph();
        $nodes = $graph['nodes'] ?? [];
        if (!is_array($nodes)) {
            return 'This rule could not be read.';
        }

        $by = ['trigger' => [], 'condition' => [], 'action' => []];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $kind = is_string($node['kind'] ?? null) ? $node['kind'] : '';
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            if (isset($by[$kind]) && '' !== $type) {
                $by[$kind][] = $type;
            }
        }

        $parts = [];
        if ([] !== $by['trigger']) {
            $parts[] = 'when ' . implode(', ', $by['trigger']);
        }
        if ([] !== $by['condition']) {
            $parts[] = 'only if ' . implode(' and ', $by['condition']);
        }
        if ([] !== $by['action']) {
            $parts[] = 'then ' . implode(', ', $by['action']);
        }

        return [] === $parts ? 'Empty rule.' : implode('; ', $parts);
    }
}
