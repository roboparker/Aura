<?php

namespace App\Doctrine;

use App\Entity\Page;
use App\Security\Permission\SpacePermission;

/**
 * Scopes Page queries to spaces the current user belongs to (#183). See
 * {@see AbstractSpaceAccessExtension} for the shared direct-or-via-group
 * membership predicate.
 */
final class PageAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Page::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'page_access';
    }

    protected function getImpersonationItemType(): string
    {
        return 'page';
    }

    protected function getPermissionCategory(): string
    {
        return SpacePermission::PAGES;
    }
}
