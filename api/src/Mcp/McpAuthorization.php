<?php

namespace App\Mcp;

use App\Entity\Discussion;
use App\Entity\Page;
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

    /**
     * Pages read like the rest of their space: any space member can view
     * (#183/#185). Mirrors the Page `Get` security expression.
     */
    public function canReadPage(Page $page, User $user): bool
    {
        return true === $page->getSpace()?->hasMember($user);
    }

    /**
     * Page edit/delete: the author OR a space admin. Mirrors the Page
     * `Patch`/`Delete` expressions. UUID comparison so the check holds
     * after an EntityManager::clear().
     */
    public function canEditPage(Page $page, User $user): bool
    {
        if (true === $page->getCreatedBy()?->getId()?->equals($user->getId())) {
            return true;
        }
        return true === $page->getSpace()?->isAdmin($user);
    }

    /**
     * Discussions read like the rest of their space: any space member
     * can browse (#91/#185). Mirrors the Discussion `Get` expression.
     */
    public function canReadDiscussion(Discussion $discussion, User $user): bool
    {
        return true === $discussion->getSpace()?->hasMember($user);
    }
}
