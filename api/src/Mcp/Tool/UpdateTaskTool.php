<?php

namespace App\Mcp\Tool;

use App\Entity\CustomFieldDefinition;
use App\Entity\GlobalCustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateTaskTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private TagRepository $tagRepo,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'update_task';
    }

    public function getDescription(): string
    {
        return 'Patch one or more fields on a task. Only fields included in the call are changed; pass status="completed" to mark done or "open" to reopen. Editable by the owner and any board member.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string', 'description' => 'UUID of the task.'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'status' => [
                    'type' => 'string',
                    'enum' => ['open', 'completed'],
                    'description' => 'open clears completedOn; completed stamps it to now.',
                ],
                'boardId' => ['type' => ['string', 'null'], 'description' => 'Move the task to a different board, or null to detach.'],
                'assigneeIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Replaces the existing assignee set.',
                ],
                'dueDate' => ['type' => ['string', 'null'], 'description' => 'ISO-8601 datetime, or null to clear.'],
                'tagIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Replaces the existing tag set.',
                ],
                'customFieldValues' => [
                    'type' => 'array',
                    'description' => 'Replaces the whole set of custom field values on the task. Each entry sets one field defined on the task\'s board (see get_custom_fields). Omit a definition to leave it unset; pass an empty array to clear all values.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'definitionId' => ['type' => 'string', 'description' => 'UUID of a CustomFieldDefinition on the task\'s board. Provide this OR globalDefinitionId, not both.'],
                            'globalDefinitionId' => ['type' => 'string', 'description' => 'UUID of a GlobalCustomFieldDefinition (instance-wide) opted into by the task\'s board. Provide this OR definitionId, not both.'],
                            'value' => ['description' => 'The value, shaped to the field\'s kind/subtype (string, number, bool, ISO date, {amount,currency} for money, an IRI for references, or an array when the field is multi).'],
                        ],
                        'required' => ['value'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['taskId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $taskId = $this->input->requireUuid('taskId', $arguments['taskId'] ?? null);
        $task = $this->em->getRepository(Task::class)->find($taskId);
        if (null === $task || !$this->authz->canEditTask($task, $user)) {
            throw McpException::notFound(sprintf('Task %s', $taskId));
        }

        if (array_key_exists('title', $arguments)) {
            $task->setTitle($this->input->requireString($arguments, 'title'));
        }
        if (array_key_exists('description', $arguments)) {
            $task->setDescription($this->input->optionalString($arguments, 'description'));
        }
        if (array_key_exists('status', $arguments)) {
            $status = $arguments['status'];
            if ('completed' === $status) {
                $task->setCompletedOn(new \DateTimeImmutable());
            } elseif ('open' === $status) {
                $task->setCompletedOn(null);
            } else {
                throw McpException::invalidInput('status must be "open" or "completed".');
            }
        }
        if (array_key_exists('dueDate', $arguments)) {
            if (null === $arguments['dueDate']) {
                $task->setDueDate(null);
            } else {
                $task->setDueDate($this->input->optionalDateTime($arguments, 'dueDate'));
            }
        }
        if (array_key_exists('boardId', $arguments)) {
            if (null === $arguments['boardId']) {
                $task->setBoard(null);
            } else {
                $boardId = $this->input->requireUuid('boardId', $arguments['boardId']);
                $board = $this->em->getRepository(Board::class)->find($boardId);
                if (null === $board || !$this->authz->canEditProject($board, $user)) {
                    throw McpException::notFound(sprintf('Board %s', $boardId));
                }
                $task->setBoard($board);
            }
        }
        if (array_key_exists('assigneeIds', $arguments)) {
            $task->getAssignees()->clear();
            foreach ($this->input->optionalStringArray($arguments, 'assigneeIds') ?? [] as $rawId) {
                $assignee = $this->em->getRepository(User::class)->find($this->input->requireUuid('assigneeIds[]', $rawId));
                if (null === $assignee) {
                    throw McpException::notFound(sprintf('Assignee %s', $rawId));
                }
                $task->addAssignee($assignee);
            }
        }
        if (array_key_exists('tagIds', $arguments)) {
            $task->getTags()->clear();
            foreach ($this->input->optionalStringArray($arguments, 'tagIds') ?? [] as $rawId) {
                $tag = $this->tagRepo->find($this->input->requireUuid('tagIds[]', $rawId));
                if (null === $tag || $tag->getOwner()?->getId()?->equals($user->getId()) !== true) {
                    throw McpException::notFound(sprintf('Tag %s', $rawId));
                }
                $task->addTag($tag);
            }
        }
        if (array_key_exists('customFieldValues', $arguments)) {
            $this->applyCustomFieldValues($task, $arguments['customFieldValues']);
        }

        $this->input->assertValid($task);
        $this->em->flush();

        return $this->serializer->task($task);
    }

    /**
     * Replace the task's whole custom-field-value set. Existing rows are
     * dropped (orphanRemoval reaps them) and rebuilt from the payload;
     * the deferrable `(task_id, definition_id)` unique constraint lets
     * the delete-then-insert collapse in one flush. Per-value shape,
     * board-scope, and required-ness are policed by
     * {@see \App\Validator\ValidCustomFieldValues} via assertValid().
     */
    private function applyCustomFieldValues(Task $task, mixed $raw): void
    {
        if (!is_array($raw)) {
            throw McpException::invalidInput('customFieldValues must be an array.');
        }
        foreach ($task->getCustomFieldValues()->toArray() as $existing) {
            $task->removeCustomFieldValue($existing);
        }
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw McpException::invalidInput('Each customFieldValues entry must be an object.');
            }
            $value = new CustomFieldValue();
            // A value targets exactly one definition source — a space-owned
            // field (definitionId) or an instance-wide global field
            // (globalDefinitionId). The XOR is policed by ValidCustomFieldValues.
            if (array_key_exists('globalDefinitionId', $entry) && null !== $entry['globalDefinitionId']) {
                $globalId = $this->input->requireUuid(
                    'customFieldValues[].globalDefinitionId',
                    $entry['globalDefinitionId'],
                );
                $global = $this->em->getRepository(GlobalCustomFieldDefinition::class)->find($globalId);
                if (null === $global) {
                    throw McpException::notFound(sprintf('Global custom field %s', $globalId));
                }
                $value->setGlobalDefinition($global);
            } else {
                $definitionId = $this->input->requireUuid(
                    'customFieldValues[].definitionId',
                    $entry['definitionId'] ?? null,
                );
                $definition = $this->em->getRepository(CustomFieldDefinition::class)->find($definitionId);
                if (null === $definition) {
                    throw McpException::notFound(sprintf('Custom field %s', $definitionId));
                }
                $value->setDefinition($definition);
            }
            $value->setValue($entry['value'] ?? null);
            $task->addCustomFieldValue($value);
        }
    }
}
