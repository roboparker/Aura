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
        if ($task->getOwner()?->getId()?->equals($user->getId())) {
            return true;
        }
        $project = $task->getProject();
        if (null !== $project && $this->isProjectMember($project, $user)) {
            return true;
        }
        return false;
    }

    /**
     * Tasks share read and write rules — every project member can edit a
     * project task alongside its owner. Mirrors the Patch security
     * expression on the Task entity.
     */
    public function canEditTask(Task $task, User $user): bool
    {
        return $this->canReadTask($task, $user);
    }

    public function canReadProject(Project $project, User $user): bool
    {
        return $this->isProjectMember($project, $user);
    }

    public function canEditProject(Project $project, User $user): bool
    {
        return $this->isProjectMember($project, $user);
    }

    public function isProjectMember(Project $project, User $user): bool
    {
        foreach ($project->getMembers() as $member) {
            if ($member->getId()?->equals($user->getId())) {
                return true;
            }
        }
        return false;
    }
}
