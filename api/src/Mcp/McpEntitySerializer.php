<?php

namespace App\Mcp;

use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\MediaObject;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;

/**
 * Plain-array serialization for the MCP tool responses. We intentionally
 * don't reuse the API Platform JSON-LD serializer here — its `@id`/`@type`
 * envelope adds noise the model has to learn around, and tool calls
 * don't need hypermedia. The shapes below are stable, hand-written
 * mirrors of the API entities, with relations flattened to ID + label
 * so the AI can chain calls (`get_task` → `assignee.id` → `assign_task`).
 */
final class McpEntitySerializer
{
    /**
     * @return array<string, mixed>
     */
    public function task(Task $task): array
    {
        return [
            'id' => (string) $task->getId(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'status' => null === $task->getCompletedOn() ? 'open' : 'completed',
            'completedOn' => $task->getCompletedOn()?->format(\DateTimeInterface::ATOM),
            'dueDate' => $task->getDueDate()?->format(\DateTimeInterface::ATOM),
            'createdOn' => $task->getCreatedOn()->format(\DateTimeInterface::ATOM),
            'recurrenceRule' => $task->getRecurrenceRule(),
            'reminders' => $task->getReminders(),
            'owner' => $this->userSummary($task->getOwner()),
            'project' => null === $task->getProject() ? null : $this->projectSummary($task->getProject()),
            'assignees' => array_map(
                fn (User $u) => $this->userSummary($u),
                $task->getAssignees()->toArray(),
            ),
            'tags' => array_map(
                fn (Tag $t) => ['id' => (string) $t->getId(), 'name' => $t->getTitle()],
                $task->getTags()->toArray(),
            ),
            'attachments' => array_map(
                fn (MediaObject $m) => $this->mediaObject($m),
                $task->getAttachments()->toArray(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function project(Project $project, ?int $taskCount = null, ?int $openTaskCount = null): array
    {
        // Members are derived from the parent space (direct + group)
        // since #185 — `Project::getEffectiveMembers()` returns a
        // dedupe-keyed map of every user with access to the project.
        $members = $project->getEffectiveMembers();
        return [
            'id' => (string) $project->getId(),
            'title' => $project->getTitle(),
            'description' => $project->getDescription(),
            'createdOn' => $project->getCreatedOn()->format(\DateTimeInterface::ATOM),
            'owner' => $this->userSummary($project->getOwner()),
            'memberCount' => count($members),
            'members' => array_map(
                fn (User $u) => $this->userSummary($u),
                array_values($members),
            ),
            'taskCount' => $taskCount,
            'openTaskCount' => $openTaskCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comment(Comment $comment): array
    {
        return [
            'id' => (string) $comment->getId(),
            'commentableType' => $comment->getCommentableType(),
            'taskId' => null === $comment->getTask() ? null : (string) $comment->getTask()->getId(),
            'pageId' => null === $comment->getPage() ? null : (string) $comment->getPage()->getId(),
            'body' => $comment->getBody(),
            'author' => $this->userSummary($comment->getAuthor()),
            'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $comment->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mediaObject(MediaObject $media): array
    {
        return [
            'id' => (string) $media->getId(),
            'name' => $media->getOriginalName(),
            'mimeType' => $media->getMimeType(),
            'byteSize' => $media->getByteSize(),
            'kind' => $media->getKind(),
            'createdOn' => $media->getCreatedOn()->format(\DateTimeInterface::ATOM),
            'downloadUrl' => $media->getDownloadUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customFieldDefinition(CustomFieldDefinition $field): array
    {
        return [
            'id' => (string) $field->getId(),
            'name' => $field->getName(),
            'kind' => $field->getKind(),
            'subtype' => $field->getSubtype(),
            'config' => $field->getConfig(),
            'footer' => $field->getFooter(),
            'nullable' => $field->isNullable(),
            'position' => $field->getPosition(),
            'projectId' => null === $field->getProject() ? null : (string) $field->getProject()->getId(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userSummary(?User $user): ?array
    {
        if (null === $user) {
            return null;
        }
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'name' => trim($user->getGivenName() . ' ' . $user->getFamilyName()),
        ];
    }

    /**
     * Compact project label embedded inside Task responses. The full
     * member list is omitted to keep the payload tight.
     *
     * @return array<string, mixed>
     */
    public function projectSummary(Project $project): array
    {
        return [
            'id' => (string) $project->getId(),
            'title' => $project->getTitle(),
        ];
    }
}
