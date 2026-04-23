<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters Project queries so non-admin users only see projects they belong
 * to. Item lookups for non-member projects return 404 rather than 403, which
 * mirrors the existence-hiding behavior of TaskOwnerExtension.
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
        // Use an EXISTS subquery rather than a join on the root query. Adding
        // a join on `members` here — even without addSelect — ends up
        // partially hydrating the Project's members collection (the join is
        // reused during result hydration), so a later writer sees a pruned
        // collection and Doctrine mis-diffs inserts on flush. The subquery
        // keeps the root query's join graph clean.
        $subQuery = sprintf(
            'SELECT 1 FROM %s project_access_probe JOIN project_access_probe.members project_access_member WHERE project_access_probe = %s AND project_access_member = :currentUser',
            Project::class,
            $rootAlias,
        );
        $queryBuilder
            ->andWhere(sprintf('EXISTS(%s)', $subQuery))
            ->setParameter('currentUser', $user);
    }
}
