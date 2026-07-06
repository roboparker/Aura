<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters Organization queries so a user only sees orgs they belong to.
 * Item lookups for non-member orgs return 404 (mirroring the other access
 * extensions' existence-hiding). Instance admins are scoped like everyone
 * else — they reach another org by impersonation.
 *
 * EXISTS-subquery on the root query (not a join) so the org's `memberships`
 * collection isn't partially hydrated by the access predicate.
 */
final class OrganizationAccessExtension implements
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
        if (Organization::class !== $resourceClass) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $subquery = sprintf(
            'SELECT 1 FROM %s org_access_m WHERE org_access_m.organization = %s AND org_access_m.user = :orgAccessUser',
            OrganizationMembership::class,
            $rootAlias,
        );
        $queryBuilder
            ->andWhere(sprintf('EXISTS(%s)', $subquery))
            ->setParameter('orgAccessUser', $user);
    }
}
