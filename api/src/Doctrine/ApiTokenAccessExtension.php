<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Scopes ApiToken queries so users only see (and can revoke)
 * their own tokens. Item-level filtering means a DELETE for somebody
 * else's token id returns 404 instead of 403, so the endpoint cannot
 * be used to enumerate token IDs across users.
 *
 * Instance admins are scoped like everyone else — they reach another
 * user's tokens only by impersonating them (`switch_user`), which works
 * because the filter resolves against the impersonated user.
 */
final class ApiTokenAccessExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if (ApiToken::class !== $resourceClass) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        // Personal tokens only — space-scoped keys (#space-roles) are managed
        // through the space's API-keys endpoint, not here.
        $queryBuilder
            ->andWhere(sprintf('%s.user = :currentUser', $rootAlias))
            ->andWhere(sprintf('%s.space IS NULL', $rootAlias))
            ->setParameter('currentUser', $user);
    }
}
