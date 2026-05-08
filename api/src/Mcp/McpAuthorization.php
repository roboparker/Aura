<?php

namespace App\Mcp;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;

/**
 * Authorization rules duplicated from the API Platform `security:`
 * expressions on Task and Project, so MCP tools can re-check access in
 * pure PHP without going through the HTTP layer.
 *
 * Keeping them in one helper means future rule changes (e.g. project
 * roles) need to land here as well as the entity attribute.
 */
final class McpAuthorization
{
    public function canReadTask(Task $task, User $user): bool
    {
        return $task->isAccessibleBy($user);
    }

    /**
     * Tasks share read and write rules — every space member can edit a
     * project task alongside its owner. Mirrors the Patch security
     * expression on the Task entity (#185).
     */
    public function canEditTask(Task $task, User $user): bool
    {
        return $this->canReadTask($task, $user);
    }

    public function canReadProject(Project $project, User $user): bool
    {
        return $project->isAccessibleBy($user);
    }

    public function canEditProject(Project $project, User $user): bool
    {
        return $project->isAccessibleBy($user);
    }

    /**
     * Retained as a thin alias so any external callers don't break with
     * the rename. New code should call `Project::isAccessibleBy()`
     * directly.
     */
    public function isProjectMember(Project $project, User $user): bool
    {
        return $project->isAccessibleBy($user);
    }
}
