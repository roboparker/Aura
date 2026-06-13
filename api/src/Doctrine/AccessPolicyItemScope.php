<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Security\Access\AccessPolicy;
use App\Security\Access\ActorPolicyResolver;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Drops collection rows the current actor's {@see AccessPolicy} hides, per its
 * per-item overrides. Actor-agnostic (impersonation, scoped tokens, agents) —
 * the policy comes from {@see ActorPolicyResolver}; a no-op for an
 * unconstrained actor.
 *
 * Only ever narrows results:
 *  - category default view/edit → exclude ids overridden to 'none',
 *  - category default 'none'    → include ONLY ids overridden to 'view'/'edit'
 *    (force empty when there are none).
 *
 * Item routes are handled by AccessPolicyListener (a 403 before the query), so
 * this is collection-only.
 */
final class AccessPolicyItemScope
{
    public function __construct(private ActorPolicyResolver $resolver)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        string $rootAlias,
        string $itemType,
        string $paramPrefix,
    ): void {
        $policy = $this->resolver->resolve();
        if (null === $policy) {
            return;
        }

        $category = AccessPolicy::categoryForItemType($itemType);
        if (null === $category) {
            return;
        }

        $categoryViewable = AccessPolicy::NONE !== $policy->categoryLevel($category);
        $partition = $policy->itemPartition($itemType);

        if ($categoryViewable) {
            $hidden = $this->toUuids($partition['none']);
            if ([] !== $hidden) {
                $queryBuilder
                    ->andWhere(sprintf('%s.id NOT IN (:%s_hidden)', $rootAlias, $paramPrefix))
                    ->setParameter($paramPrefix . '_hidden', $hidden);
            }

            return;
        }

        // Category hidden: only explicitly-elevated ids are visible.
        $visible = $this->toUuids($partition['visible']);
        if ([] === $visible) {
            // Always-false predicate on the (non-null) PK — DQL-safe, unlike a
            // bare "1 = 0" literal.
            $queryBuilder->andWhere(sprintf('%s.id IS NULL', $rootAlias));

            return;
        }
        $queryBuilder
            ->andWhere(sprintf('%s.id IN (:%s_visible)', $rootAlias, $paramPrefix))
            ->setParameter($paramPrefix . '_visible', $visible);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Uuid>
     */
    private function toUuids(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (Uuid::isValid($id)) {
                $out[] = Uuid::fromString($id);
            }
        }

        return $out;
    }
}
