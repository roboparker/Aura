<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Page;
use Doctrine\ORM\QueryBuilder;

/**
 * Free-text filter for the `Page` collection: `?search={q}` narrows
 * to pages whose title/body tsvector matches the query (#183).
 *
 * Uses the same shape as {@see BoardSearchFilter}: STORED
 * `search_vector` generated column on `page` (title weighted A, body B), GIN-indexed,
 * `websearch_to_tsquery` so the navbar's typed input passes through
 * without parsing. Replaces the default order with `ts_rank` desc when
 * a query is present.
 *
 * Trimmed-empty queries fall through silently so the navbar can leave
 * the param attached when the field is cleared.
 */
final class PageSearchFilter extends AbstractFilter
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
        if (self::PARAMETER !== $property || Page::class !== $resourceClass) {
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
            ->setParameter($queryParam, $trimmed)
            ->addSelect(sprintf(
                'SEARCH_VECTOR_RANK(%s.searchVector, :%s) AS HIDDEN %s',
                $rootAlias,
                $queryParam,
                $rankParam,
            ))
            ->resetDQLPart('orderBy')
            ->orderBy($rankParam, 'DESC')
            ->addOrderBy(sprintf('%s.createdAt', $rootAlias), 'DESC');
    }

    public function getDescription(string $resourceClass): array
    {
        if (Page::class !== $resourceClass) {
            return [];
        }

        return [
            self::PARAMETER => [
                'property' => self::PARAMETER,
                'type' => 'string',
                'required' => false,
                'description' => 'Postgres full-text search across page title and body. Ranked by relevance.',
                'openapi' => new Parameter(
                    name: self::PARAMETER,
                    in: 'query',
                    example: 'onboarding',
                ),
            ],
        ];
    }
}
