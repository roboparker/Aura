<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Comment;
use App\Entity\UserGroup;
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
 *  - Discussion comments: visible to any member of the discussion's
 *    space.
 *
 * The branches OR together, and the per-comment commentable_type
 * keeps them mutually exclusive on a given row.
 *
 * Instance admins are scoped like everyone else — they reach another
 * user's comments only by impersonating them (`switch_user`), which
 * works because the filter resolves against the impersonated user.
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
            'SELECT 1 FROM %s ca_t_grp_obj JOIN ca_t_grp_obj.memberships ca_t_grp_member WHERE ca_t_grp_obj.space = ca_project.space AND ca_t_grp_member.user = :currentUser',
            UserGroup::class,
        );

        // Page branch: comment is on a page whose space contains the
        // caller (direct or via group).
        $pageSpaceDirect = sprintf(
            'SELECT 1 FROM %s ca_p_direct WHERE ca_p_direct.space = ca_page.space AND ca_p_direct.user = :currentUser',
            SpaceMembership::class,
        );
        $pageSpaceGroup = sprintf(
            'SELECT 1 FROM %s ca_p_grp_obj JOIN ca_p_grp_obj.memberships ca_p_grp_member WHERE ca_p_grp_obj.space = ca_page.space AND ca_p_grp_member.user = :currentUser',
            UserGroup::class,
        );

        // Discussion branch: comment is on a discussion whose space
        // contains the caller (direct or via group).
        $discussionSpaceDirect = sprintf(
            'SELECT 1 FROM %s ca_d_direct WHERE ca_d_direct.space = ca_discussion.space AND ca_d_direct.user = :currentUser',
            SpaceMembership::class,
        );
        $discussionSpaceGroup = sprintf(
            'SELECT 1 FROM %s ca_d_grp_obj JOIN ca_d_grp_obj.memberships ca_d_grp_member WHERE ca_d_grp_obj.space = ca_discussion.space AND ca_d_grp_member.user = :currentUser',
            UserGroup::class,
        );

        // Feedback branch: the feedback board is instance-level, so any
        // authenticated user (already guaranteed above) can read every
        // ticket's comments — the branch just needs the FK to be set.
        $queryBuilder
            ->leftJoin(sprintf('%s.task', $rootAlias), 'ca_task')
            ->leftJoin('ca_task.project', 'ca_project')
            ->leftJoin(sprintf('%s.page', $rootAlias), 'ca_page')
            ->leftJoin(sprintf('%s.discussion', $rootAlias), 'ca_discussion')
            ->leftJoin(sprintf('%s.feedback', $rootAlias), 'ca_feedback')
            ->andWhere(sprintf(
                '(ca_task.id IS NOT NULL AND (%s OR EXISTS(%s) OR EXISTS(%s)))
                 OR (ca_page.id IS NOT NULL AND (EXISTS(%s) OR EXISTS(%s)))
                 OR (ca_discussion.id IS NOT NULL AND (EXISTS(%s) OR EXISTS(%s)))
                 OR ca_feedback.id IS NOT NULL',
                $taskOwnerCheck,
                $taskSpaceDirect,
                $taskSpaceGroup,
                $pageSpaceDirect,
                $pageSpaceGroup,
                $discussionSpaceDirect,
                $discussionSpaceGroup,
            ))
            ->setParameter('currentUser', $user);
    }
}
