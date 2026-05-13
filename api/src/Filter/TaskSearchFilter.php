<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Comment;
use App\Entity\CustomFieldValue;
use App\Entity\Task;
use Doctrine\ORM\QueryBuilder;

/**
 * Free-text filter for the `Task` collection: `?search={q}` narrows to
 * tasks whose title/description tsvector matches the query, OR whose
 * comment thread contains a body that matches, OR whose custom field
 * values match.
 *
 * Uses Postgres full-text search via the `search_vector` generated
 * columns on `task` and `comment` (Version20260504090000) and on
 * `custom_field_value` (Version20260505000000), all GIN-indexed. Title
 * is weighted A and description B in the task vector, so title matches
 * rank above description matches at equal frequencies. When the search
 * param is present, results are ordered by `ts_rank` descending — the
 * filter installs an ORDER BY that the controller's existing default
 * order (`position ASC, createdOn DESC`) is replaced with via API
 * Platform's order-replace semantics.
 *
 * Whitespace-trimmed empty queries fall through silently so the navbar
 * search bar can leave the param attached without breaking the listing
 * when the field is cleared.
 */
final class TaskSearchFilter extends AbstractFilter
{
    public const PARAMETER = 'search';

    private const MAX_LENGTH = 200;

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

        $trimmed = trim($value);
        if ('' === $trimmed) {
            return;
        }
        // Cap to avoid pathological queries from blowing up the to_tsquery cost.
        $trimmed = mb_substr($trimmed, 0, self::MAX_LENGTH);

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryParam = $queryNameGenerator->generateParameterName('searchQuery');
        $rankParam = $queryNameGenerator->generateParameterName('searchRank');
        $commentSubAlias = $queryNameGenerator->generateJoinAlias('searchComment');
        $cfvSubAlias = $queryNameGenerator->generateJoinAlias('searchCfv');

        // websearch_to_tsquery accepts user-typed strings (quoted phrases,
        // OR/`-` operators) without us hand-parsing — and never raises on
        // invalid syntax the way to_tsquery does. The OR chain matches if
        // any of: title/description, comment body, or custom field value
        // hits the query.
        $queryBuilder
            ->andWhere(sprintf(
                "SEARCH_VECTOR_MATCH(%s.searchVector, :%s) = TRUE "
                . "OR EXISTS (SELECT 1 FROM %s %s WHERE %s.task = %s "
                . "AND SEARCH_VECTOR_MATCH(%s.searchVector, :%s) = TRUE) "
                . "OR EXISTS (SELECT 1 FROM %s %s WHERE %s.task = %s "
                . "AND SEARCH_VECTOR_MATCH(%s.searchVector, :%s) = TRUE)",
                $rootAlias,
                $queryParam,
                Comment::class,
                $commentSubAlias,
                $commentSubAlias,
                $rootAlias,
                $commentSubAlias,
                $queryParam,
                CustomFieldValue::class,
                $cfvSubAlias,
                $cfvSubAlias,
                $rootAlias,
                $cfvSubAlias,
                $queryParam,
            ))
            ->setParameter($queryParam, $trimmed);

        // Replace the default ORDER BY with relevance-first when a query
        // is present. Tasks without a direct title/description hit (i.e.
        // matched only via a comment) get rank 0 so they sort last.
        $queryBuilder
            ->addSelect(sprintf(
                'SEARCH_VECTOR_RANK(%s.searchVector, :%s) AS HIDDEN %s',
                $rootAlias,
                $queryParam,
                $rankParam,
            ))
            ->resetDQLPart('orderBy')
            ->orderBy($rankParam, 'DESC')
            ->addOrderBy(sprintf('%s.createdOn', $rootAlias), 'DESC');
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
                'description' => 'Postgres full-text search across task title, description, comments, and custom field values. Ranked by relevance.',
                'openapi' => new Parameter(
                    name: self::PARAMETER,
                    in: 'query',
                    example: 'launch checklist',
                ),
            ],
        ];
    }
}
