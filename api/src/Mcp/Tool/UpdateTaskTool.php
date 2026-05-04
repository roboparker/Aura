<?php

namespace App\Mcp\Tool;

use App\Entity\Project;
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
        return 'Patch one or more fields on a task. Only fields included in the call are changed; pass status="completed" to mark done or "open" to reopen. Editable by the owner and any project member.';
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
                'projectId' => ['type' => ['string', 'null'], 'description' => 'Move the task to a different project, or null to detach.'],
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
        if (array_key_exists('projectId', $arguments)) {
            if (null === $arguments['projectId']) {
                $task->setProject(null);
            } else {
                $projectId = $this->input->requireUuid('projectId', $arguments['projectId']);
                $project = $this->em->getRepository(Project::class)->find($projectId);
                if (null === $project || !$this->authz->canEditProject($project, $user)) {
                    throw McpException::notFound(sprintf('Project %s', $projectId));
                }
                $task->setProject($project);
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

        $this->input->assertValid($task);
        $this->em->flush();

        return $this->serializer->task($task);
    }
}
