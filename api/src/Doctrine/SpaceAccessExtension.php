<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Space;
use App\Entity\SpaceGroupMembership;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters Space queries so non-admin users only see spaces they
 * belong to — directly via `SpaceMembership` or transitively via a
 * `UserGroup` listed in `SpaceGroupMembership`. Item lookups for
 * non-member spaces return 404 rather than 403, mirroring
 * ProjectAccessExtension.
 *
 * Implemented as an EXISTS subquery on the root query (rather than a
 * join) so the Space's `userMemberships` and `groupMemberships`
 * collections are not partially hydrated by the access predicate —
 * the same hazard ProjectAccessExtension documents at length.
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
        if (Space::class !== $resourceClass) {
            return;
        }
        if ($this->security->isGranted('ROLE_ADMIN')) {
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
        // Group-inherited membership: any SpaceGroupMembership where the
        // attached UserGroup contains the current user.
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s space_access_group JOIN space_access_group.userGroup space_access_group_obj JOIN space_access_group_obj.members space_access_group_member WHERE space_access_group.space = %s AND space_access_group_member = :currentUser',
            SpaceGroupMembership::class,
            $rootAlias,
        );
        $queryBuilder
            ->andWhere(sprintf('(EXISTS(%s) OR EXISTS(%s))', $directSubquery, $groupSubquery))
            ->setParameter('currentUser', $user);
    }
}
