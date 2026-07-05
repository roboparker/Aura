<?php

namespace App\Doctrine;

use App\Entity\BillingProject;
use App\Security\Permission\SpacePermission;

/**
 * Scopes BillingProject queries to the spaces the current user belongs to, via
 * the `billing_project.space` FK, and read-gates on the admin-reserved
 * `invoices` permission category. Members reach boards for time tracking
 * through the minimal picker instead. See {@see AbstractSpaceAccessExtension}.
 */
final class BillingProjectAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return BillingProject::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'billing_project_access';
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
