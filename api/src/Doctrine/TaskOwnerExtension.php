<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\UserGroup;
use App\Entity\SpaceMembership;
use App\Entity\Task;
use App\Entity\User;
use App\Security\Permission\ActorContext;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters Task queries so users only see tasks they can act on:
 * tasks they own directly, plus any task attached to a board whose
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
    public function __construct(
        private Security $security,
        private AccessPolicyItemScope $accessPolicyItemScope,
        private SpacePermissionResolver $spacePermissions,
        private ActorContext $actor,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->applyFilter($queryBuilder, $resourceClass);

        // Drop tasks hidden by per-item impersonation overrides (no-op when
        // not impersonating). Item routes are guarded by the listener.
        if (Task::class === $resourceClass) {
            $this->accessPolicyItemScope->applyToCollection(
                $queryBuilder,
                $queryBuilder->getRootAliases()[0],
                'task',
                'task_access_imp',
            );
            $this->applyReadScope($queryBuilder);
        }
    }

    /**
     * Drop board tasks in spaces where the user's roles deny reading tasks
     * (#space-roles), keeping the caller's own tasks. No-op for unrestricted
     * users. Relies on the `tp_access` (board) join added by applyFilter and
     * its `:currentUser` parameter.
     */
    private function applyReadScope(QueryBuilder $queryBuilder): void
    {
        $user = $this->security->getUser();
        // Scoped keys are confined by applyFilter + gated by the listener.
        if (!$user instanceof User || null !== $this->actor->scopedKey()) {
            return;
        }
        $denied = $this->spacePermissions->readDeniedSpaceIds($user, SpacePermission::TASKS);
        if (0 === count($denied)) {
            return;
        }
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(sprintf(
                '(IDENTITY(tp_access.space) NOT IN (:task_read_denied) OR %s.owner = :currentUser)',
                $rootAlias,
            ))
            ->setParameter('task_read_denied', $denied);
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

        $rootAlias = $queryBuilder->getRootAliases()[0];

        // Space-scoped API key: only tasks of boards in the key's space
        // (standalone tasks have no space and are never reachable by a key).
        $key = $this->actor->scopedKey();
        if (null !== $key) {
            $space = $key->getSpace();
            $queryBuilder
                ->leftJoin(sprintf('%s.board', $rootAlias), 'tp_access')
                ->andWhere('IDENTITY(tp_access.space) = :task_key_space')
                ->setParameter('task_key_space', null === $space ? null : (string) $space->getId());

            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }
        // The task is visible when ANY of:
        //  - the caller owns it (covers personal/standalone tasks),
        //  - the caller is a direct member of the parent board's space,
        //  - the caller is a member via a group attached to that space.
        // Standalone tasks (board IS NULL) only match the owner branch.
        //
        // DQL doesn't accept chained associations like `task.board.space`
        // in subqueries, so we left-join through `board` and reference
        // `tp_access.space` from the joined alias. The LEFT join keeps
        // standalone tasks visible because their board is NULL — both
        // EXISTS branches simply fail to match for them.
        $directSubquery = sprintf(
            'SELECT 1 FROM %s task_access_direct WHERE task_access_direct.space = tp_access.space AND task_access_direct.user = :currentUser',
            SpaceMembership::class,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s task_access_group_obj JOIN task_access_group_obj.memberships task_access_group_member WHERE task_access_group_obj.space = tp_access.space AND task_access_group_member.user = :currentUser',
            UserGroup::class,
        );
        // ...and not in an organization that's mid-deletion — including via the
        // owner branch, since a board task in a deleted org is that org's
        // content whoever happens to own the row. A standalone task has no
        // board, so `tp_access.space` is NULL, the inner subquery matches
        // nothing, and the NOT EXISTS leaves it visible.
        $queryBuilder
            ->leftJoin(sprintf('%s.board', $rootAlias), 'tp_access')
            ->andWhere(sprintf(
                '((%s.owner = :currentUser OR EXISTS(%s) OR EXISTS(%s)) AND %s)',
                $rootAlias,
                $directSubquery,
                $groupSubquery,
                SpaceMembershipDql::spaceOrganizationIsLive('tp_access', 'task_org'),
            ))
            ->setParameter('currentUser', $user);
    }
}
