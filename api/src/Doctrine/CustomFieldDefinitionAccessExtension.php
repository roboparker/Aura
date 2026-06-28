<?php

namespace App\Doctrine;

use App\Entity\CustomFieldDefinition;
use App\Security\Permission\SpacePermission;

/**
 * Scopes CustomFieldDefinition queries to spaces the current user belongs
 * to (#185). Uses the denormalised `cfd.space` FK to avoid joining through
 * `project`. See {@see AbstractSpaceAccessExtension} for the shared
 * direct-or-via-group membership predicate.
 */
final class CustomFieldDefinitionAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return CustomFieldDefinition::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'cfd_access';
    }

    protected function getImpersonationItemType(): ?string
    {
        // CFDs follow their project's space + the 'projects' category default;
        // they aren't individually addressable for per-item overrides.
        return null;
    }

    protected function getPermissionCategory(): string
    {
        return SpacePermission::CUSTOM_FIELDS;
    }
}
