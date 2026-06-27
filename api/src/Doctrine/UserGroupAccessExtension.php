<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters UserGroup queries so users only see groups whose owning space
 * they belong to (#groups-space) — directly or via another group in that
 * space. Item lookups for inaccessible groups return 404 rather than 403,
 * matching the existence-hiding behavior used elsewhere (Project, Task).
 *
 * Instance admins are scoped like everyone else — they reach another
 * user's groups only by impersonating them (`switch_user`), which works
 * because the filter resolves against the impersonated user.
 */
final class UserGroupAccessExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private Security $security)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->applyFilter($queryBuilder, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->applyFilter($queryBuilder, $resourceClass);
    }

    private function applyFilter(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (UserGroup::class !== $resourceClass) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        // Visible when the caller belongs to the group's owning space —
        // directly or via any group in that space. The root alias holds the
        // `space` association, so the shared membership fragment applies.
        // EXISTS (not a join) so the collection isn't partially hydrated.
        $queryBuilder
            ->andWhere(
                SpaceMembershipDql::userBelongsToProjectSpace($rootAlias, 'ug_access', 'currentUser'),
            )
            ->setParameter('currentUser', $user);
    }
}
