<?php

namespace App\Doctrine;

use App\Entity\Tag;
use App\Security\Permission\SpacePermission;

/**
 * Scopes Tag queries to spaces the current user belongs to (#tags). Tags are
 * space-scoped: every member of a space shares its tag pool. The shared
 * direct-or-via-group membership predicate lives in
 * {@see AbstractSpaceAccessExtension}. Tags have no
 * per-item impersonation overrides, so {@see getImpersonationItemType} is null
 * (like CustomFieldDefinition).
 */
final class TagAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Tag::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'tag_access';
    }

    protected function getImpersonationItemType(): ?string
    {
        return null;
    }

    protected function getPermissionCategory(): string
    {
        return SpacePermission::TAGS;
    }
}
