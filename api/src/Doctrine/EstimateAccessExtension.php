<?php

namespace App\Doctrine;

use App\Entity\Estimate;
use App\Security\Permission\SpacePermission;

/**
 * Scopes Estimate queries to the spaces the current user belongs to (#652),
 * via the `estimate.space` FK, and read-gates on the admin-reserved
 * `invoices` permission category — the exact shape of
 * {@see InvoiceAccessExtension}.
 */
final class EstimateAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Estimate::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'estimate_access';
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
