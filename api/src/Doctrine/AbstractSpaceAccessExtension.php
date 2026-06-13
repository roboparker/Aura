<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Shared base for the access extensions that scope a resource to the spaces
 * the current user belongs to (#185). All of them have the same shape:
 * require an authenticated User, then AND an EXISTS predicate over the
 * resource's `space` FK matching direct membership (`SpaceMembership`) OR
 * transitive membership via a `UserGroup` (`SpaceGroupMembership`). EXISTS
 * subqueries (rather than joins on the root) keep the resource's own
 * collections from being partially hydrated by the access predicate, and
 * unreachable item lookups return 404 rather than 403 so existence isn't
 * leaked.
 *
 * Instance admins are scoped like everyone else — they reach another
 * user's data only by impersonating them (`switch_user`), which works
 * because the filter resolves against the impersonated user.
 *
 * Concrete extensions only declare which resource they guard and a unique
 * alias prefix for the subqueries; the EXISTS fragment itself lives in
 * {@see SpaceMembershipDql}.
 */
abstract class AbstractSpaceAccessExtension implements
    QueryCollectionExtensionInterface,
    QueryItemExtensionInterface
{
    public function __construct(
        protected Security $security,
        protected ImpersonationItemScope $impersonationItemScope,
    ) {
    }

    /** Fully-qualified class name of the resource this extension scopes. */
    abstract protected function getResourceClass(): string;

    /** Collision-free prefix for the EXISTS subquery aliases. */
    abstract protected function getAliasPrefix(): string;

    /**
     * The per-item impersonation override type for this resource (e.g.
     * 'project'), or null when the resource has no item-level overrides
     * (CustomFieldDefinition). Drives ImpersonationItemScope filtering.
     */
    abstract protected function getImpersonationItemType(): ?string;

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->applyFilter($queryBuilder, $resourceClass);

        // Drop rows hidden by per-item impersonation overrides (no-op when
        // not impersonating). Item routes are guarded by the listener.
        $itemType = $this->getImpersonationItemType();
        if (null !== $itemType && $this->getResourceClass() === $resourceClass) {
            $this->impersonationItemScope->applyToCollection(
                $queryBuilder,
                $queryBuilder->getRootAliases()[0],
                $itemType,
                $this->getAliasPrefix() . '_imp',
            );
        }
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
        if ($this->getResourceClass() !== $resourceClass) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(SpaceMembershipDql::userBelongsToProjectSpace(
                $rootAlias,
                $this->getAliasPrefix(),
                'currentUser',
            ))
            ->setParameter('currentUser', $user);
    }
}
