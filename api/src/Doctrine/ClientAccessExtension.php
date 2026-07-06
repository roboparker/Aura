<?php

namespace App\Doctrine;

use App\Entity\Client;
use App\Security\Permission\SpacePermission;

/**
 * Scopes Client queries to the spaces the current user belongs to (#445), via
 * the `client.space` FK, and read-gates on the admin-reserved `invoices`
 * permission category. See {@see AbstractSpaceAccessExtension}.
 */
final class ClientAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return Client::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'client_access';
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
