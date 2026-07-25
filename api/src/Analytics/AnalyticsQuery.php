<?php

declare(strict_types=1);

namespace App\Analytics;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;

/**
 * Shared SQL plumbing for the analytics metrics (#analytics).
 *
 * Every metric is the same shape — bucket a date column by period, sum a
 * numeric column, scope to a space — so the bucketing and parameter binding
 * live here rather than being re-typed (and mis-typed) per metric.
 *
 * `$interval` never reaches SQL as user input: it maps through {@see TRUNC} to
 * a fixed literal, because `date_trunc` takes its unit as a string and
 * interpolating a request value there would be an injection.
 */
final class AnalyticsQuery
{
    /** Allowed bucket sizes → the exact `date_trunc` unit. */
    private const TRUNC = ['day' => 'day', 'week' => 'week', 'month' => 'month'];

    /** How each bucket is rendered on the wire. */
    private const FORMAT = ['day' => 'YYYY-MM-DD', 'week' => 'IYYY-"W"IW', 'month' => 'YYYY-MM'];

    /**
     * @phpstan-assert-if-true 'day'|'week'|'month' $interval
     */
    public static function isValidInterval(string $interval): bool
    {
        return isset(self::TRUNC[$interval]);
    }

    /**
     * Sum `$valueExpr`, bucketed by `$dateExpr`, over `$fromClause`.
     *
     * `$fromClause`, `$dateExpr`, `$valueExpr`, `$currencyExpr` and
     * `$extraWhere` are **SQL written by the calling metric** — never request
     * input. Everything derived from the request travels as a bound parameter.
     * Payments, for instance, pass a join because `invoice_payment` carries no
     * `space_id` of its own; it inherits the space from its invoice.
     *
     * @param array<string, mixed>                                              $extraParams
     * @param array<string, ArrayParameterType|ParameterType|Type|string>       $extraTypes  DBAL types, e.g. ArrayParameterType::STRING for an IN list
     *
     * @return list<array{currency: string|null, points: list<array{period: string, value: int}>}>
     */
    public static function series(
        Connection $db,
        string $fromClause,
        string $spacePredicate,
        string $dateExpr,
        string $valueExpr,
        ?string $currencyExpr,
        string $spaceId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $interval,
        string $extraWhere = '',
        array $extraParams = [],
        array $extraTypes = [],
    ): array {
        $trunc = self::TRUNC[$interval] ?? 'month';
        $format = self::FORMAT[$interval] ?? 'YYYY-MM';

        $sql = sprintf(
            'SELECT to_char(date_trunc(%s, %s), %s) AS period,
                    %s AS currency,
                    COALESCE(SUM(%s), 0)::bigint AS value
             FROM %s
             WHERE %s
               AND %s >= :from
               AND %s <= :to
               %s
             GROUP BY 1, 2
             ORDER BY 1',
            $db->quote($trunc),
            $dateExpr,
            $db->quote($format),
            $currencyExpr ?? 'NULL::varchar',
            $valueExpr,
            $fromClause,
            $spacePredicate,
            $dateExpr,
            $dateExpr,
            '' === $extraWhere ? '' : 'AND ' . $extraWhere,
        );

        /** @var list<array{period: string, currency: string|null, value: int|string}> $rows */
        $rows = $db->executeQuery(
            $sql,
            array_merge(
                ['space' => $spaceId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
                $extraParams,
            ),
            $extraTypes,
        )->fetchAllAssociative();

        $byCurrency = [];
        foreach ($rows as $row) {
            $currency = $row['currency'];
            $key = $currency ?? '';
            $byCurrency[$key] ??= ['currency' => $currency, 'points' => []];
            $byCurrency[$key]['points'][] = [
                'period' => $row['period'],
                'value' => (int) $row['value'],
            ];
        }

        return array_values($byCurrency);
    }
}
