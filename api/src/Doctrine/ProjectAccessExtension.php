<?php

namespace App\Doctrine;

use App\Entity\Project;
use App\Security\Permission\SpacePermission;

/**
 * Scopes Project queries to the spaces the current user belongs to, via
 * the `project.space` FK, and read-gates on the admin-reserved
 * `invoices` permission category. Members reach boards for time tracking
 * through the minimal picker instead. See {@see AbstractSpaceAccessExtension}.
 */
final class ProjectAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Project::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'project_access';
    }

    protected function getImpersonationItemType(): ?string
    {
        return null;
    }

    protected function getPermissionCategory(): string
    {
        return SpacePermission::INVOICES;
    }
}
