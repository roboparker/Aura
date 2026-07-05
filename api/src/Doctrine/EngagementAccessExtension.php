<?php

namespace App\Doctrine;

use App\Entity\Engagement;
use App\Security\Permission\SpacePermission;

/**
 * Scopes Engagement queries to the spaces the current user belongs to, via
 * the `engagement.space` FK, and read-gates on the admin-reserved
 * `invoices` permission category. Members reach boards for time tracking
 * through the minimal picker instead. See {@see AbstractSpaceAccessExtension}.
 */
final class EngagementAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Engagement::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'engagement_access';
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
