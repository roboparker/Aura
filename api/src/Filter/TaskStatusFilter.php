<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Task;
use Doctrine\ORM\QueryBuilder;

/**
 * `?status=open|completed` filter for the Task collection. Maps to the
 * `completedOn` column being NULL or NOT NULL — the Task entity doesn't
 * have a discrete status enum yet, but the open/completed split is the
 * one users actually want to filter by from the search page.
 *
 * Anything other than the two known values is silently ignored so a
 * stray query string doesn't 500 the listing.
 */
final class TaskStatusFilter extends AbstractFilter
{
    public const PARAMETER = 'status';

    /**
     * @param array<string, mixed> $context
     */
    protected function filterProperty(
        string $property,
        $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (self::PARAMETER !== $property || Task::class !== $resourceClass) {
            return;
        }
        if (!is_string($value)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        match ($value) {
            'open' => $queryBuilder->andWhere(sprintf('%s.completedOn IS NULL', $rootAlias)),
            'completed' => $queryBuilder->andWhere(sprintf('%s.completedOn IS NOT NULL', $rootAlias)),
            default => null,
        };
    }

    public function getDescription(string $resourceClass): array
    {
        if (Task::class !== $resourceClass) {
            return [];
        }

        return [
            self::PARAMETER => [
                'property' => self::PARAMETER,
                'type' => 'string',
                'required' => false,
                'description' => 'Filter tasks by status: "open" (not yet completed) or "completed".',
                // enum lives on the parameter's schema, not as a sibling
                // attribute, so it has to ride along inside `schema`.
                'openapi' => new Parameter(
                    name: self::PARAMETER,
                    in: 'query',
                    schema: ['type' => 'string', 'enum' => ['open', 'completed']],
                ),
            ],
        ];
    }
}
