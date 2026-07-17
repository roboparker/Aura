<?php

namespace App\Doctrine;

use App\Entity\ExpenseCategory;
use App\Security\Permission\SpacePermission;

/**
 * Scopes ExpenseCategory queries to the spaces the current user belongs to
 * (#671). Categories are picker data for anyone logging expenses, so reads
 * ride the `time_entries` permission category (writes are invoices-gated at
 * the resource level).
 */
final class ExpenseCategoryAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return ExpenseCategory::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'expense_category_access';
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
