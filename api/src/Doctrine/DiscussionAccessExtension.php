<?php

namespace App\Doctrine;

use App\Entity\Discussion;
use App\Security\Permission\SpacePermission;

/**
 * Scopes Discussion queries to spaces the current user belongs to (#185).
 * Uses the denormalised `discussion.space` FK so we don't have to join
 * through `discussion.board`. See {@see AbstractSpaceAccessExtension} for
 * the shared direct-or-via-group membership predicate.
 */
final class DiscussionAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Discussion::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'disc_access';
    }

    protected function getImpersonationItemType(): string
    {
        return 'discussion';
    }

    protected function getPermissionCategory(): string
    {
        return SpacePermission::DISCUSSIONS;
    }
}
