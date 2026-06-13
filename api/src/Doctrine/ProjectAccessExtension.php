<?php

namespace App\Doctrine;

use App\Entity\Project;

/**
 * Filters Project queries so non-admin users only see projects in spaces
 * they belong to (#185). See {@see AbstractSpaceAccessExtension} for the
 * shared direct-or-via-group membership predicate.
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
        return 'project';
    }
}
