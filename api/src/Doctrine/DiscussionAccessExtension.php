<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Discussion;
use App\Entity\SpaceGroupMembership;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Scopes Discussion queries to spaces the current user belongs to (#185).
 * Membership can be direct or transitive via a `UserGroup`. Uses the
 * denormalised `discussion.space` FK so we don't have to join through
 * `discussion.project`. Item lookups for unreachable rows return 404
 * instead of 403 so we don't leak existence.
 */
final class DiscussionAccessExtension implements
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
        if (Discussion::class !== $resourceClass) {
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
            'SELECT 1 FROM %s disc_access_direct WHERE disc_access_direct.space = %s.space AND disc_access_direct.user = :currentUser',
            SpaceMembership::class,
            $rootAlias,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s disc_access_group JOIN disc_access_group.userGroup disc_access_group_obj JOIN disc_access_group_obj.members disc_access_group_member WHERE disc_access_group.space = %s.space AND disc_access_group_member = :currentUser',
            SpaceGroupMembership::class,
            $rootAlias,
        );
        $queryBuilder
            ->andWhere(sprintf('(EXISTS(%s) OR EXISTS(%s))', $directSubquery, $groupSubquery))
            ->setParameter('currentUser', $user);
    }
}
