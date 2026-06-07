<?php

namespace App\Doctrine;

use App\Entity\Page;

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
}
