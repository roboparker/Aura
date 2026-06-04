<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\CustomFieldDefinition;
use App\Entity\SpaceGroupMembership;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Scopes CustomFieldDefinition queries to spaces the current user
 * belongs to (#185). Uses the denormalised `cfd.space` FK to avoid
 * joining through `project`. Non-member item GETs return 404 rather
 * than 403 so we don't leak existence.
 */
final class CustomFieldDefinitionAccessExtension implements
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
        if (CustomFieldDefinition::class !== $resourceClass) {
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
        $directSubquery = sprintf(
            'SELECT 1 FROM %s cfd_access_direct WHERE cfd_access_direct.space = %s.space AND cfd_access_direct.user = :currentUser',
            SpaceMembership::class,
            $rootAlias,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s cfd_access_group JOIN cfd_access_group.userGroup cfd_access_group_obj JOIN cfd_access_group_obj.memberships cfd_access_group_member WHERE cfd_access_group.space = %s.space AND cfd_access_group_member.user = :currentUser',
            SpaceGroupMembership::class,
            $rootAlias,
        );
        $queryBuilder
            ->andWhere(sprintf('(EXISTS(%s) OR EXISTS(%s))', $directSubquery, $groupSubquery))
            ->setParameter('currentUser', $user);
    }
}
