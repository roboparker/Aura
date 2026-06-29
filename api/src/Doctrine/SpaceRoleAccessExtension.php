<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\SpaceRole;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters SpaceRole queries so users only see roles of spaces they belong to
 * (#space-roles) — directly or via a group in that space. Item lookups for
 * inaccessible roles return 404 (existence-hiding), matching the rest of the
 * space-scoped surface. Writes are gated to space admins by the entity
 * security expressions.
 */
final class SpaceRoleAccessExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if (SpaceRole::class !== $resourceClass) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(
                SpaceMembershipDql::userBelongsToProjectSpace($rootAlias, 'space_role_access', 'currentUser'),
            )
            ->setParameter('currentUser', $user);
    }
}
