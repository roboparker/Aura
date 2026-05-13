<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Task;
use Doctrine\ORM\QueryBuilder;

/**
 * Boolean filter for `Task` collections: `?overdue=true` narrows the result to
 * incomplete tasks whose `dueDate` is strictly in the past. Used by the navbar
 * badge so the chrome can pull only `totalItems` (`itemsPerPage=1`) without
 * walking the whole list client-side.
 *
 * `?overdue=false` is intentionally a no-op rather than its inverse; "not
 * overdue" mixes "completed", "future-due", and "no due date" which are rarely
 * a single useful set in product UI. Other values are ignored.
 */
final class OverdueFilter extends AbstractFilter
{
    public const PARAMETER = 'overdue';

    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (self::PARAMETER !== $property || Task::class !== $resourceClass) {
            return;
        }

        if (!$this->isTruthy($value)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $nowParam = $queryNameGenerator->generateParameterName('overdueNow');

        $queryBuilder
            ->andWhere(sprintf('%s.dueDate < :%s', $rootAlias, $nowParam))
            ->andWhere(sprintf('%s.completedOn IS NULL', $rootAlias))
            ->setParameter($nowParam, new \DateTimeImmutable());
    }

    public function getDescription(string $resourceClass): array
    {
        if (Task::class !== $resourceClass) {
            return [];
        }

        return [
            self::PARAMETER => [
                'property' => self::PARAMETER,
                'type' => 'bool',
                'required' => false,
                'description' => 'When true, restricts the collection to overdue tasks (incomplete with a past due date).',
                'openapi' => new Parameter(
                    name: self::PARAMETER,
                    in: 'query',
                    allowEmptyValue: false,
                    example: true,
                ),
            ],
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_int($value)) {
            return 1 === $value;
        }

        return false;
    }
}
