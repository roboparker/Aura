<?php

namespace App\Mcp\Tool;

use App\Entity\Board;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Repository\TagRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CreateTaskTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private TaskRepository $tasks,
        private TagRepository $tagRepo,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'create_task';
    }

    public function getDescription(): string
    {
        return 'Create a new task owned by the authenticated user. Use when capturing a new piece of work; pass a boardId to attach the task to a shared board the user is a member of.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Short title for the task.'],
                'description' => ['type' => 'string', 'description' => 'Optional Markdown body.'],
                'boardId' => ['type' => 'string', 'description' => 'UUID of a board the user is a member of. Omit for a personal task.'],
                'assigneeIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'UUIDs of users to assign. Each must be the owner or a board member.',
                ],
                'dueDate' => ['type' => 'string', 'description' => 'Due date as an ISO-8601 datetime.'],
                'tagIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'UUIDs of existing tags belonging to the user.',
                ],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $title = $this->input->requireString($arguments, 'title');
        $description = $this->input->optionalString($arguments, 'description');
        $boardId = $this->input->optionalUuid('boardId', $arguments['boardId'] ?? null);
        $dueDate = $this->input->optionalDateTime($arguments, 'dueDate');

        $task = new Task();
        $task->setOwner($user);
        $task->setTitle($title);
        if (null !== $description) {
            $task->setDescription($description);
        }
        if (null !== $dueDate) {
            $task->setDueDate($dueDate);
        }

        if (null !== $boardId) {
            $board = $this->em->getRepository(Board::class)->find($boardId);
            if (null === $board || !$this->authz->canEditProject($board, $user)) {
                throw McpException::notFound(sprintf('Board %s', $boardId));
            }
            $task->setBoard($board);
        }

        $assigneeIds = $this->input->optionalStringArray($arguments, 'assigneeIds') ?? [];
        foreach ($assigneeIds as $rawId) {
            $assignee = $this->em->getRepository(User::class)->find($this->input->requireUuid('assigneeIds[]', $rawId));
            if (null === $assignee) {
                throw McpException::notFound(sprintf('Assignee %s', $rawId));
            }
            $task->addAssignee($assignee);
        }

        $tagIds = $this->input->optionalStringArray($arguments, 'tagIds') ?? [];
        foreach ($tagIds as $rawId) {
            $tag = $this->tagRepo->find($this->input->requireUuid('tagIds[]', $rawId));
            if (null === $tag || $tag->getOwner()?->getId()?->equals($user->getId()) !== true) {
                throw McpException::notFound(sprintf('Tag %s', $rawId));
            }
            $task->addTag($tag);
        }

        // Mirror TaskOwnerProcessor's positioning so MCP-created tasks slot
        // in at the top of the user's list, same as PWA-created ones.
        $min = $this->tasks->findMinPositionForOwner($user);
        $task->setPosition(null === $min ? 0 : $min - 1);

        $this->input->assertValid($task);
        $this->em->persist($task);
        $this->em->flush();

        return $this->serializer->task($task);
    }
}
