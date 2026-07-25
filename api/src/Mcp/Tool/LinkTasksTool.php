<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Task;
use App\Entity\TaskRelationship;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a directed relationship between two tasks — the write half of the
 * relationships surface an agent previously could only read. `parent` makes the
 * target a subtask of the source; `required` is a dependency (source must
 * finish first); `related`/`duplicate` are cross-links. Self-links, duplicates
 * (either direction), and parent cycles are rejected by the entity validator.
 */
final class LinkTasksTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'link_tasks';
    }

    public function getDescription(): string
    {
        return 'Create a relationship between two tasks. type=parent makes target a subtask of source; required = a dependency (source before target); related / duplicate = cross-links. Both tasks must be editable by the user.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sourceTaskId' => ['type' => 'string', 'description' => 'UUID of the source task (the parent, or the prerequisite for `required`).'],
                'targetTaskId' => ['type' => 'string', 'description' => 'UUID of the target task (the subtask, or the dependent for `required`).'],
                'type' => [
                    'type' => 'string',
                    'enum' => TaskRelationship::TYPES,
                    'description' => 'parent | required | related | duplicate.',
                ],
            ],
            'required' => ['sourceTaskId', 'targetTaskId', 'type'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $sourceId = $this->input->requireUuid('sourceTaskId', $this->input->requireString($arguments, 'sourceTaskId'));
        $targetId = $this->input->requireUuid('targetTaskId', $this->input->requireString($arguments, 'targetTaskId'));
        $type = $this->input->requireString($arguments, 'type');

        if (!in_array($type, TaskRelationship::TYPES, true)) {
            throw McpException::invalidInput(
                sprintf('Unknown type "%s". Expected one of: %s.', $type, implode(', ', TaskRelationship::TYPES)),
            );
        }

        $source = $this->em->getRepository(Task::class)->find($sourceId);
        // 404-shaped for tasks the caller can't edit — same as the create rule
        // on the entity (both ends must be editable).
        if (null === $source || !$this->authz->canEditTask($source, $user)) {
            throw McpException::notFound(sprintf('Task %s', $sourceId));
        }
        $target = $this->em->getRepository(Task::class)->find($targetId);
        if (null === $target || !$this->authz->canEditTask($target, $user)) {
            throw McpException::notFound(sprintf('Task %s', $targetId));
        }

        $relationship = new TaskRelationship();
        $relationship->setSource($source)->setTarget($target)->setType($type);

        // ValidTaskRelationship enforces no self-link, no duplicate (either
        // direction), single parent, and no parent cycle — surfaced as 422.
        $this->input->assertValid($relationship);
        $this->em->persist($relationship);
        $this->em->flush();

        return [
            'id' => (string) $relationship->getId(),
            'type' => $type,
            'sourceTaskId' => (string) $source->getId(),
            'targetTaskId' => (string) $target->getId(),
            'label' => TaskRelationship::labelFor($type, true),
        ];
    }
}
