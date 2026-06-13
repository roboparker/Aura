<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Uid\Uuid;

/**
 * Drops collection rows an impersonating admin may not even see, per the
 * target user's per-item impersonation overrides (the `impersonationItemAccess`
 * preference). No-op when the session isn't impersonating.
 *
 * Composes with the access extensions' existing membership predicate — this
 * only ever narrows the result set further:
 *
 *  - category default is view/edit → exclude ids explicitly overridden to
 *    'none',
 *  - category default is 'none' → include ONLY ids explicitly overridden to
 *    'view'/'edit' (and force an empty set when there are none).
 *
 * Item routes are handled separately by ImpersonationAccessListener (a 403
 * before the query runs), so this is collection-only.
 */
final class ImpersonationItemScope
{
    public function __construct(private Security $security)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        string $rootAlias,
        string $itemType,
        string $paramPrefix,
    ): void {
        $token = $this->security->getToken();
        if (!$token instanceof SwitchUserToken) {
            return;
        }
        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $category = User::impersonationCategoryForItemType($itemType);
        if (null === $category) {
            return;
        }

        $categoryViewable = 'none' !== $user->getImpersonationLevel($category);
        $partition = $user->impersonationItemPartition($itemType);

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
            // Nothing is viewable — force an empty result with an always-false
            // predicate on the (non-null) primary key. DQL-safe, unlike a
            // bare "1 = 0" literal comparison.
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
