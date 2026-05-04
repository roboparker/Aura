<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Comment;
use App\Entity\Task;
use Doctrine\ORM\QueryBuilder;

/**
 * Free-text filter for the `Task` collection: `?search={q}` narrows to
 * tasks whose title or description matches the query, OR whose comment
 * thread contains a matching body. Implementation is case-insensitive
 * `ILIKE %q%` on Postgres — fast enough at current scale and avoids the
 * extra moving parts of `tsvector` for the first iteration of #87. The
 * filter shape (`AbstractFilter` with a single parameter) keeps the
 * door open to swap in a Postgres FTS or external Meilisearch backend
 * later without touching callers.
 *
 * Whitespace-trimmed empty queries fall through silently so the navbar
 * search bar can leave the param attached without breaking the listing
 * when the field is cleared. Multi-word queries are passed straight
 * into the LIKE — the user can write `payment refactor` and it'll
 * match either word in either column thanks to the OR fan-out, which
 * is closer to the intent than requiring all-word matches.
 */
final class TaskSearchFilter extends AbstractFilter
{
    public const PARAMETER = 'search';

    private const MAX_LENGTH = 200;

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
        // Cap to avoid pathological queries from blowing up the LIKE.
        $trimmed = mb_substr($trimmed, 0, self::MAX_LENGTH);

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $needle = '%' . self::escapeLike($trimmed) . '%';
        $needleParam = $queryNameGenerator->generateParameterName('searchNeedle');
        $commentSubAlias = $queryNameGenerator->generateJoinAlias('searchComment');

        $queryBuilder
            ->andWhere(sprintf(
                "LOWER(%s.title) LIKE LOWER(:%s) ESCAPE '\\' "
                . "OR LOWER(%s.description) LIKE LOWER(:%s) ESCAPE '\\' "
                . "OR EXISTS (SELECT 1 FROM %s %s WHERE %s.task = %s "
                . "AND LOWER(%s.body) LIKE LOWER(:%s) ESCAPE '\\')",
                $rootAlias,
                $needleParam,
                $rootAlias,
                $needleParam,
                Comment::class,
                $commentSubAlias,
                $commentSubAlias,
                $rootAlias,
                $commentSubAlias,
                $needleParam,
            ))
            ->setParameter($needleParam, $needle);
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
                'description' => 'Free-text search across task title, description, and comments. Case-insensitive substring match.',
                'openapi' => [
                    'example' => 'launch checklist',
                ],
            ],
        ];
    }

    /**
     * Escapes the three SQL LIKE wildcards so a query like `50%_off`
     * matches that literal text instead of every row. We use `\` as the
     * escape character to match the `ESCAPE '\\'` clause above.
     */
    private static function escapeLike(string $needle): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $needle,
        );
    }
}
