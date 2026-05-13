<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Comment;
use App\Entity\SpaceGroupMembership;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters {@see Comment} queries to those whose parent (task or page)
 * the current user can read. Mirrors the existing per-parent access
 * rules now that both surfaces flow through one comment table:
 *
 *  - Task comments: visible to the task owner OR a member of the task
 *    project's space (#185). Standalone (projectless) tasks fall back
 *    to owner-only.
 *  - Page comments: visible to any member of the page's space.
 *
 * Both branches OR together, and the per-comment commentable_type
 * keeps them mutually exclusive on a given row.
 */
final class CommentAccessExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if (Comment::class !== $resourceClass) {
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

        // Task branch: comment is on a task whose owner is the caller
        // OR whose project space contains the caller (direct or via
        // group). LEFT joins so a page comment (no task FK) just
        // doesn't match this branch rather than blowing up the row.
        $taskOwnerCheck = 'ca_task.owner = :currentUser';
        $taskSpaceDirect = sprintf(
            'SELECT 1 FROM %s ca_t_direct WHERE ca_t_direct.space = ca_project.space AND ca_t_direct.user = :currentUser',
            SpaceMembership::class,
        );
        $taskSpaceGroup = sprintf(
            'SELECT 1 FROM %s ca_t_grp JOIN ca_t_grp.userGroup ca_t_grp_obj JOIN ca_t_grp_obj.members ca_t_grp_member WHERE ca_t_grp.space = ca_project.space AND ca_t_grp_member = :currentUser',
            SpaceGroupMembership::class,
        );

        // Page branch: comment is on a page whose space contains the
        // caller (direct or via group).
        $pageSpaceDirect = sprintf(
            'SELECT 1 FROM %s ca_p_direct WHERE ca_p_direct.space = ca_page.space AND ca_p_direct.user = :currentUser',
            SpaceMembership::class,
        );
        $pageSpaceGroup = sprintf(
            'SELECT 1 FROM %s ca_p_grp JOIN ca_p_grp.userGroup ca_p_grp_obj JOIN ca_p_grp_obj.members ca_p_grp_member WHERE ca_p_grp.space = ca_page.space AND ca_p_grp_member = :currentUser',
            SpaceGroupMembership::class,
        );

        $queryBuilder
            ->leftJoin(sprintf('%s.task', $rootAlias), 'ca_task')
            ->leftJoin('ca_task.project', 'ca_project')
            ->leftJoin(sprintf('%s.page', $rootAlias), 'ca_page')
            ->andWhere(sprintf(
                '(ca_task.id IS NOT NULL AND (%s OR EXISTS(%s) OR EXISTS(%s)))
                 OR (ca_page.id IS NOT NULL AND (EXISTS(%s) OR EXISTS(%s)))',
                $taskOwnerCheck,
                $taskSpaceDirect,
                $taskSpaceGroup,
                $pageSpaceDirect,
                $pageSpaceGroup,
            ))
            ->setParameter('currentUser', $user);
    }
}
