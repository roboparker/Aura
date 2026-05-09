<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Page;
use App\Entity\SpaceGroupMembership;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Scopes Page queries to spaces the current user belongs to (#183).
 * Membership can be direct (`SpaceMembership`) or transitive via a
 * `UserGroup` (`SpaceGroupMembership`). Item lookups for unreachable
 * pages return 404 rather than 403, mirroring the rest of the API.
 *
 * Same EXISTS-subquery shape as the existing access extensions —
 * keeping the root query's join graph clean so Page collections
 * (`comments`, eventually attachments) aren't partially hydrated by
 * the access predicate.
 */
final class PageAccessExtension implements
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
        if (Page::class !== $resourceClass) {
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
            'SELECT 1 FROM %s page_access_direct WHERE page_access_direct.space = %s.space AND page_access_direct.user = :currentUser',
            SpaceMembership::class,
            $rootAlias,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s page_access_group JOIN page_access_group.userGroup page_access_group_obj JOIN page_access_group_obj.members page_access_group_member WHERE page_access_group.space = %s.space AND page_access_group_member = :currentUser',
            SpaceGroupMembership::class,
            $rootAlias,
        );
        $queryBuilder
            ->andWhere(sprintf('(EXISTS(%s) OR EXISTS(%s))', $directSubquery, $groupSubquery))
            ->setParameter('currentUser', $user);
    }
}
