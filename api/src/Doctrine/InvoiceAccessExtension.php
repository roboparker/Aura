<?php

namespace App\Doctrine;

use App\Entity\Invoice;
use App\Security\Permission\SpacePermission;

/**
 * Scopes Invoice queries to the spaces the current user belongs to (#445), via
 * the `invoice.space` FK, and read-gates on the admin-reserved `invoices`
 * permission category. See {@see AbstractSpaceAccessExtension}.
 */
final class InvoiceAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Invoice::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'invoice_access';
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
