<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Discussion;
use Doctrine\ORM\QueryBuilder;

/**
 * Free-text filter for the `Discussion` collection: `?search={q}`
 * narrows to discussions whose title/body tsvector matches the query.
 *
 * Mirrors {@see TaskSearchFilter}: uses the `search_vector` STORED
 * generated column on `discussion` (Version20260506010000), GIN-indexed,
 * with `websearch_to_tsquery` so users can paste the same kind of
 * query they'd type into Google. When the param is present, results
 * are ordered by `ts_rank` desc with `isPinned`/`createdAt` as
 * tiebreakers — replacing the default order.
 *
 * Whitespace-trimmed empty queries fall through silently so the
 * search bar can leave the param attached without breaking the
 * listing when the field is cleared.
 */
final class DiscussionSearchFilter extends AbstractFilter
{
    public const PARAMETER = 'search';

    private const MAX_LENGTH = 200;

    /**
     * @param array<string, mixed> $context
     */
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (self::PARAMETER !== $property || Discussion::class !== $resourceClass) {
            return;
        }
        if (!is_string($value)) {
            return;
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            return;
        }
        $trimmed = mb_substr($trimmed, 0, self::MAX_LENGTH);

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryParam = $queryNameGenerator->generateParameterName('searchQuery');
        $rankParam = $queryNameGenerator->generateParameterName('searchRank');

        $queryBuilder
            ->andWhere(sprintf(
                'SEARCH_VECTOR_MATCH(%s.searchVector, :%s) = TRUE',
                $rootAlias,
                $queryParam,
            ))
            ->setParameter($queryParam, $trimmed);

        // Pinned threads stay first in both modes; `?sort=recent` then
        // orders newest-first instead of by relevance.
        $filters = $context['filters'] ?? null;
        if ('recent' === (is_array($filters) ? ($filters['sort'] ?? null) : null)) {
            $queryBuilder
                ->resetDQLPart('orderBy')
                ->orderBy(sprintf('%s.isPinned', $rootAlias), 'DESC')
                ->addOrderBy(sprintf('%s.createdAt', $rootAlias), 'DESC');

            return;
        }

        $queryBuilder
            ->addSelect(sprintf(
                'SEARCH_VECTOR_RANK(%s.searchVector, :%s) AS HIDDEN %s',
                $rootAlias,
                $queryParam,
                $rankParam,
            ))
            ->resetDQLPart('orderBy')
            ->orderBy(sprintf('%s.isPinned', $rootAlias), 'DESC')
            ->addOrderBy($rankParam, 'DESC')
            ->addOrderBy(sprintf('%s.createdAt', $rootAlias), 'DESC');
    }

    public function getDescription(string $resourceClass): array
    {
        if (Discussion::class !== $resourceClass) {
            return [];
        }

        return [
            self::PARAMETER => [
                'property' => self::PARAMETER,
                'type' => 'string',
                'required' => false,
                'description' => 'Postgres full-text search across discussion title and body. Pinned threads first, then ranked by relevance.',
                'openapi' => new Parameter(
                    name: self::PARAMETER,
                    in: 'query',
                    example: 'pricing question',
                ),
            ],
        ];
    }
}
