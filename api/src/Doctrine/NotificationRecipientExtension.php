<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters Notification queries so users only see notifications
 * addressed to them. Item-level filtering means cross-user lookups return
 * 404 rather than leak existence.
 *
 * Instance admins are scoped like everyone else — they reach another
 * user's notifications only by impersonating them (`switch_user`), which
 * works because the filter resolves against the impersonated user.
 */
final class NotificationRecipientExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if (Notification::class !== $resourceClass) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(sprintf('%s.recipient = :currentUser', $rootAlias))
            ->setParameter('currentUser', $user);
    }
}
