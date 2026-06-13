<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\SpaceGroupMembership;
use App\Entity\SpaceMembership;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters Task queries so users only see tasks they can act on:
 * tasks they own directly, plus any task attached to a project whose
 * space they belong to (#185). Applies to both collection and item
 * queries as defense-in-depth against leaks; operation-level `security`
 * already covers item access.
 *
 * Instance admins are scoped like everyone else — they reach another
 * user's tasks only by impersonating them (`switch_user`), which works
 * because the filter resolves against the impersonated user.
 */
final class TaskOwnerExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if (Task::class !== $resourceClass) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        // The task is visible when ANY of:
        //  - the caller owns it (covers personal/standalone tasks),
        //  - the caller is a direct member of the parent project's space,
        //  - the caller is a member via a group attached to that space.
        // Standalone tasks (project IS NULL) only match the owner branch.
        //
        // DQL doesn't accept chained associations like `task.project.space`
        // in subqueries, so we left-join through `project` and reference
        // `tp_access.space` from the joined alias. The LEFT join keeps
        // standalone tasks visible because their project is NULL — both
        // EXISTS branches simply fail to match for them.
        $directSubquery = sprintf(
            'SELECT 1 FROM %s task_access_direct WHERE task_access_direct.space = tp_access.space AND task_access_direct.user = :currentUser',
            SpaceMembership::class,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s task_access_group JOIN task_access_group.userGroup task_access_group_obj JOIN task_access_group_obj.memberships task_access_group_member WHERE task_access_group.space = tp_access.space AND task_access_group_member.user = :currentUser',
            SpaceGroupMembership::class,
        );
        $queryBuilder
            ->leftJoin(sprintf('%s.project', $rootAlias), 'tp_access')
            ->andWhere(sprintf(
                '%s.owner = :currentUser OR EXISTS(%s) OR EXISTS(%s)',
                $rootAlias,
                $directSubquery,
                $groupSubquery,
            ))
            ->setParameter('currentUser', $user);
    }
}
