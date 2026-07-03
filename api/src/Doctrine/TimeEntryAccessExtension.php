<?php

namespace App\Doctrine;

use App\Entity\TimeEntry;
use App\Security\Permission\SpacePermission;

/**
 * Scopes TimeEntry queries to the spaces the current user belongs to (#444),
 * via the denormalised `time_entry.space` FK. Time entries have no per-item
 * impersonation overrides, so {@see getImpersonationItemType()} is null; reads
 * are role-gated on the `time_entries` category. See
 * {@see AbstractSpaceAccessExtension} for the shared membership predicate.
 */
final class TimeEntryAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return TimeEntry::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'time_entry_access';
    }

    protected function getImpersonationItemType(): ?string
    {
        return null;
    }

    protected function getPermissionCategory(): string
    {
        return SpacePermission::TIME_ENTRIES;
    }
}
