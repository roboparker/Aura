<?php

namespace App\Mcp;

use App\Entity\Discussion;
use App\Entity\Page;
use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;

/**
 * Authorization rules duplicated from the API Platform `security:`
 * expressions on Task and Board, so MCP tools can re-check access in
 * pure PHP without going through the HTTP layer.
 *
 * Keeping them in one helper means future rule changes need to land here as
 * well as the entity attribute. Per-space role permissions (#space-roles) are
 * layered on via {@see SpacePermissionResolver}, mirroring the entity voter:
 * owners/authors keep their bypass, and a member's roles can restrict the rest.
 */
final class McpAuthorization
{
    public function __construct(private readonly SpacePermissionResolver $permissions)
    {
    }

    public function canReadTask(Task $task, User $user): bool
    {
        return $this->ownsTask($task, $user)
            || ($task->isAccessibleBy($user)
                && $this->permissions->can($user, $task->getBoard()?->getSpace(), SpacePermission::TASKS, SpacePermission::READ));
    }

    /**
     * Tasks share read and write rules — every space member can edit a
     * board task alongside its owner. Mirrors the Patch security
     * expression on the Task entity (#185), now role-gated.
     */
    public function canEditTask(Task $task, User $user): bool
    {
        return $this->ownsTask($task, $user)
            || ($task->isAccessibleBy($user)
                && $this->permissions->can($user, $task->getBoard()?->getSpace(), SpacePermission::TASKS, SpacePermission::UPDATE));
    }

    public function canReadProject(Board $board, User $user): bool
    {
        return $board->isAccessibleBy($user)
            && $this->permissions->can($user, $board->getSpace(), SpacePermission::PROJECTS, SpacePermission::READ);
    }

    public function canEditProject(Board $board, User $user): bool
    {
        return $board->isAccessibleBy($user)
            && $this->permissions->can($user, $board->getSpace(), SpacePermission::PROJECTS, SpacePermission::UPDATE);
    }

    private function ownsTask(Task $task, User $user): bool
    {
        return true === $task->getOwner()?->getId()?->equals($user->getId());
    }

    /**
     * Retained as a thin alias so any external callers don't break with
     * the rename. New code should call `Board::isAccessibleBy()`
     * directly.
     */
    public function isProjectMember(Board $board, User $user): bool
    {
        return $board->isAccessibleBy($user);
    }

    /**
     * Pages read like the rest of their space: any space member can view
     * (#183/#185). Mirrors the Page `Get` security expression.
     */
    public function canReadPage(Page $page, User $user): bool
    {
        return true === $page->getSpace()?->hasMember($user)
            && $this->permissions->can($user, $page->getSpace(), SpacePermission::PAGES, SpacePermission::READ);
    }

    /**
     * Page edit/delete: the author OR a space admin OR a member with the
     * pages.update role. Mirrors the Page `Patch`/`Delete` expressions.
     */
    public function canEditPage(Page $page, User $user): bool
    {
        if (true === $page->getCreatedBy()?->getId()?->equals($user->getId())) {
            return true;
        }
        if (true === $page->getSpace()?->isAdmin($user)) {
            return true;
        }
        return true === $page->getSpace()?->hasMember($user)
            && $this->permissions->can($user, $page->getSpace(), SpacePermission::PAGES, SpacePermission::UPDATE);
    }

    /**
     * Discussions read like the rest of their space: any space member
     * can browse (#91/#185), now role-gated.
     */
    public function canReadDiscussion(Discussion $discussion, User $user): bool
    {
        return true === $discussion->getSpace()?->hasMember($user)
            && $this->permissions->can($user, $discussion->getSpace(), SpacePermission::DISCUSSIONS, SpacePermission::READ);
    }
}
