<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\PageComment;
use App\Entity\SpaceGroupMembership;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Scopes PageComment queries to comments on pages whose space the
 * current user belongs to (#183). Joins through `page_comment.page`
 * and reuses the same `(direct OR via group)` membership pattern as
 * the rest of the access extensions; non-member item lookups return
 * 404 rather than 403.
 */
final class PageCommentAccessExtension implements
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
        if (PageComment::class !== $resourceClass) {
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
        // Inner-join `page` so we can reference `pc_page.space` from
        // both EXISTS subqueries without DQL chained-association limits.
        $directSubquery = sprintf(
            'SELECT 1 FROM %s pc_access_direct WHERE pc_access_direct.space = pc_page.space AND pc_access_direct.user = :currentUser',
            SpaceMembership::class,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s pc_access_group JOIN pc_access_group.userGroup pc_access_group_obj JOIN pc_access_group_obj.members pc_access_group_member WHERE pc_access_group.space = pc_page.space AND pc_access_group_member = :currentUser',
            SpaceGroupMembership::class,
        );
        $queryBuilder
            ->innerJoin(sprintf('%s.page', $rootAlias), 'pc_page')
            ->andWhere(sprintf('(EXISTS(%s) OR EXISTS(%s))', $directSubquery, $groupSubquery))
            ->setParameter('currentUser', $user);
    }
}
