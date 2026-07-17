<?php

namespace App\Doctrine;

use App\Entity\Expense;
use App\Security\Permission\SpacePermission;

/**
 * Scopes Expense queries to the spaces the current user belongs to (#650),
 * via the denormalised `expense.space` FK — the exact shape of
 * {@see TimeEntryAccessExtension} (expenses ride the same `time_entries`
 * permission category as the rest of the billing inputs).
 */
final class ExpenseAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Expense::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'expense_access';
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
