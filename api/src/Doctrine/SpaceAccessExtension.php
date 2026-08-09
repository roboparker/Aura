<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters Space queries so users only see spaces they belong to —
 * directly via `SpaceMembership` or transitively via a `UserGroup`
 * owned by the space. Item lookups for non-member spaces return 404
 * rather than 403, mirroring BoardAccessExtension.
 *
 * Instance admins are scoped like everyone else — they reach another
 * user's spaces only by impersonating them (`switch_user`), which works
 * because the filter resolves against the impersonated user.
 *
 * Implemented as an EXISTS subquery on the root query (rather than a
 * join) so the Space's `userMemberships` and `groups`
 * collections are not partially hydrated by the access predicate —
 * the same hazard BoardAccessExtension documents at length.
 */
final class SpaceAccessExtension implements
    QueryCollectionExtensionInterface,
    QueryItemExtensionInterface
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
        $this->applyFilter($queryBuilder, $resourceClass, hideDeleted: true);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        // Item reads stay open on a space inside its grace period so its admins
        // can load the "scheduled for deletion, restorable until …" state.
        // Hiding it here would 404 the page they'd undo from. The space's
        // *contents* are hidden regardless — that guard lives in
        // SpaceMembershipDql, which every content resource routes through.
        $this->applyFilter($queryBuilder, $resourceClass, hideDeleted: false);
    }

    private function applyFilter(QueryBuilder $queryBuilder, string $resourceClass, bool $hideDeleted): void
    {
        if (Space::class !== $resourceClass) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        // Direct user membership via SpaceMembership.
        $directSubquery = sprintf(
            'SELECT 1 FROM %s space_access_direct WHERE space_access_direct.space = %s AND space_access_direct.user = :currentUser',
            SpaceMembership::class,
            $rootAlias,
        );
        // Group-inherited membership: any UserGroup owned by this space
        // whose roster contains the current user.
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s space_access_group_obj JOIN space_access_group_obj.memberships space_access_group_member WHERE space_access_group_obj.space = %s AND space_access_group_member.user = :currentUser',
            UserGroup::class,
            $rootAlias,
        );
        // A space that's mid-deletion — or whose organization is — drops out of
        // listings: the grace period only means something if members stop
        // working in the thing that's scheduled to vanish.
        $queryBuilder
            ->andWhere(sprintf('(EXISTS(%s) OR EXISTS(%s))', $directSubquery, $groupSubquery))
            ->setParameter('currentUser', $user);

        if ($hideDeleted) {
            $queryBuilder->andWhere(SpaceMembershipDql::organizationIsLive($rootAlias, 'space_access_org'));
        }
    }
}
