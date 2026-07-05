<?php

namespace App\Doctrine;

use App\Entity\Board;
use App\Security\Permission\SpacePermission;

/**
 * Filters Board queries so non-admin users only see boards in spaces
 * they belong to (#185). See {@see AbstractSpaceAccessExtension} for the
 * shared direct-or-via-group membership predicate.
 */
final class BoardAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Board::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'board_access';
    }

    protected function getImpersonationItemType(): string
    {
        return 'board';
    }

    protected function getPermissionCategory(): string
    {
        return SpacePermission::PROJECTS;
    }
}
