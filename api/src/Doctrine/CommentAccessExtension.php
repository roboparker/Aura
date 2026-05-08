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
 * Filters Comment queries to those whose parent task the current user can
 * read — mirrors TaskOwnerExtension by joining through `comment.task` and
 * accepting either task ownership or membership in the project's space
 * (#185). Applied to both collection and item queries so cross-task
 * lookups return 404 instead of leaking the row's existence.
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
        // Match if the task owner is the caller, or if the caller is a
        // member of the parent project's space (direct or via group).
        // Standalone tasks (no project) only match the owner branch.
        //
        // DQL can't follow `ca_task.project.space` in a subquery, so we
        // left-join through `project` and reference `ca_project.space`
        // from the joined alias. The LEFT join keeps comments on
        // standalone tasks visible because the project IS NULL — the
        // EXISTS branches simply fail to match for them.
        $directSubquery = sprintf(
            'SELECT 1 FROM %s comment_access_direct WHERE comment_access_direct.space = ca_project.space AND comment_access_direct.user = :currentUser',
            SpaceMembership::class,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s comment_access_group JOIN comment_access_group.userGroup comment_access_group_obj JOIN comment_access_group_obj.members comment_access_group_member WHERE comment_access_group.space = ca_project.space AND comment_access_group_member = :currentUser',
            SpaceGroupMembership::class,
        );
        $queryBuilder
            ->innerJoin(sprintf('%s.task', $rootAlias), 'ca_task')
            ->leftJoin('ca_task.project', 'ca_project')
            ->andWhere(sprintf(
                'ca_task.owner = :currentUser OR EXISTS(%s) OR EXISTS(%s)',
                $directSubquery,
                $groupSubquery,
            ))
            ->setParameter('currentUser', $user);
    }
}
