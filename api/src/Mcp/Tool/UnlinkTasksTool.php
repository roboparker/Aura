<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Task;
use App\Entity\TaskRelationship;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Repository\TaskRelationshipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes a relationship between two tasks, identified by the same
 * (source, target, type) triple {@see LinkTasksTool} creates — no relationship
 * id needed, which the model rarely has. Deleting a `parent` link promotes the
 * subtask back to a standalone task.
 */
final class UnlinkTasksTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private TaskRelationshipRepository $relationships,
        private McpAuthorization $authz,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'unlink_tasks';
    }

    public function getDescription(): string
    {
        return 'Remove a relationship between two tasks, given the same source, target and type used to create it. Removing a parent link makes the subtask standalone again.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sourceTaskId' => ['type' => 'string', 'description' => 'UUID of the source task.'],
                'targetTaskId' => ['type' => 'string', 'description' => 'UUID of the target task.'],
                'type' => [
                    'type' => 'string',
                    'enum' => TaskRelationship::TYPES,
                    'description' => 'The relationship type to remove.',
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

        $source = $this->em->getRepository(Task::class)->find($sourceId);
        $target = $this->em->getRepository(Task::class)->find($targetId);
        // Deleting a relationship needs edit rights on the tasks involved,
        // mirroring the entity's Delete rule.
        if (
            null === $source || null === $target
            || !$this->authz->canEditTask($source, $user)
            || !$this->authz->canEditTask($target, $user)
        ) {
            throw McpException::notFound('Task relationship');
        }

        $relationship = $this->relationships->findBetween($source, $target, $type);
        if (null === $relationship) {
            throw McpException::notFound(
                sprintf('No %s relationship from %s to %s', $type, $sourceId, $targetId),
            );
        }

        $this->em->remove($relationship);
        $this->em->flush();

        return ['removed' => true];
    }
}
