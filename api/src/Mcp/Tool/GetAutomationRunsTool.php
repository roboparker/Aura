<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Repository\AutomationRunRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recent executions of one automation rule, step by step.
 *
 * The counterpart to {@see ListAutomationsTool}: that says what a rule *would*
 * do, this says what it *did*. Together they answer "why did my task move?"
 * without anyone opening the board.
 *
 * Skipped steps are included, not filtered out — a rule that fired and did
 * nothing is the common case, and the condition that stopped it is the whole
 * explanation.
 */
final class GetAutomationRunsTool implements McpToolInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private AutomationRunRepository $runs,
        private McpAuthorization $authz,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'get_automation_runs';
    }

    public function getDescription(): string
    {
        return 'Show recent runs of one automation rule, including which conditions passed or skipped and which actions ran. Use to explain why a task changed by itself, or why a rule did not fire.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'automationId' => ['type' => 'string', 'description' => 'The rule to inspect (from list_automations).'],
                'limit' => [
                    'type' => 'integer',
                    'description' => sprintf('Runs to return (1-%d, default %d).', self::MAX_LIMIT, self::DEFAULT_LIMIT),
                ],
            ],
            'required' => ['automationId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $id = $this->input->requireUuid('automationId', $arguments['automationId'] ?? null);
        $rule = $this->em->getRepository(Automation::class)->find($id);

        $board = $rule?->getBoard();
        if (!$rule instanceof Automation || null === $board || !$this->authz->canReadBoard($board, $user)) {
            throw McpException::notFound('No automation with that id.');
        }

        $limit = $this->input->optionalInt($arguments, 'limit') ?? self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        return [
            'automation' => $rule->getName(),
            'items' => array_map(
                fn (AutomationRun $run) => [
                    'status' => $run->getStatus(),
                    'task' => $run->getTaskTitle(),
                    'taskId' => null === $run->getTask() ? null : (string) $run->getTask()->getId(),
                    // Non-zero means another rule caused this one to fire —
                    // the first thing to check when rules look like they're
                    // fighting each other.
                    'causedByAnotherRule' => $run->getDepth() > 0,
                    'steps' => $run->getSteps(),
                    'ranAt' => $run->getRanAt()->format(\DATE_ATOM),
                ],
                $this->runs->recentFor($rule, $limit),
            ),
        ];
    }
}
