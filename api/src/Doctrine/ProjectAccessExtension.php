<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Project;
use App\Entity\SpaceGroupMembership;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters Project queries so non-admin users only see projects in spaces
 * they belong to (#185). Membership can be direct (`SpaceMembership`)
 * or transitive via a `UserGroup` linked through `SpaceGroupMembership`.
 * Item lookups for spaces the user can't reach return 404 rather than
 * 403, mirroring the existence-hiding behavior of the other access
 * extensions.
 */
final class ProjectAccessExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if (Project::class !== $resourceClass) {
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
        // Use EXISTS subqueries rather than joins on the root query so
        // none of the project's collections are partially hydrated by
        // the access predicate. Two branches: direct user membership in
        // the project's space, or transitive membership via a group.
        $directSubquery = sprintf(
            'SELECT 1 FROM %s project_access_direct WHERE project_access_direct.space = %s.space AND project_access_direct.user = :currentUser',
            SpaceMembership::class,
            $rootAlias,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s project_access_group JOIN project_access_group.userGroup project_access_group_obj JOIN project_access_group_obj.members project_access_group_member WHERE project_access_group.space = %s.space AND project_access_group_member = :currentUser',
            SpaceGroupMembership::class,
            $rootAlias,
        );
        $queryBuilder
            ->andWhere(sprintf('(EXISTS(%s) OR EXISTS(%s))', $directSubquery, $groupSubquery))
            ->setParameter('currentUser', $user);
    }
}
