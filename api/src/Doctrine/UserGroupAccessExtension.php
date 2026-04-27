<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters UserGroup queries so non-admin users only see groups they own or
 * belong to. Item lookups for non-member groups return 404 rather than 403,
 * matching the existence-hiding behavior used elsewhere (Project, Task).
 */
final class UserGroupAccessExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if (UserGroup::class !== $resourceClass) {
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
        // EXISTS subquery rather than a join on the root query — see the same
        // note on ProjectAccessExtension. A members join here would partially
        // hydrate the collection and corrupt later flushes.
        //
        // Also OR on `owner` so a freshly transferred owner who hasn't been
        // added to members yet still sees their group.
        $subQuery = sprintf(
            'SELECT 1 FROM %s user_group_access_probe JOIN user_group_access_probe.members user_group_access_member WHERE user_group_access_probe = %s AND user_group_access_member = :currentUser',
            UserGroup::class,
            $rootAlias,
        );
        $queryBuilder
            ->andWhere(sprintf('(EXISTS(%s) OR %s.owner = :currentUser)', $subQuery, $rootAlias))
            ->setParameter('currentUser', $user);
    }
}
