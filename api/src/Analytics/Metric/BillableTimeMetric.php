<?php

declare(strict_types=1);

namespace App\Analytics\Metric;

use App\Analytics\AnalyticsMetricInterface;
use App\Analytics\AnalyticsQuery;
use App\Entity\Space;
use App\Security\Permission\SpacePermission;
use Doctrine\DBAL\Connection;

/**
 * Billable time tracked per period. Divide by {@see TrackedTimeMetric} for
 * utilisation.
 *
 * Billability is the entry's snapshotted flag (derived from its category at
 * save time), not the category's current setting — so re-classifying a
 * category tomorrow can't silently rewrite last quarter's numbers.
 */
final class BillableTimeMetric implements AnalyticsMetricInterface
{
    public function key(): string
    {
        return 'billable_time';
    }

    public function label(): string
    {
        return 'Billable time';
    }

    public function permissionCategory(): string
    {
        return SpacePermission::TIME_ENTRIES;
    }

    public function unit(): string
    {
        return 'seconds';
    }

    public function series(
        Connection $db,
        Space $space,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $interval,
    ): array {
        return AnalyticsQuery::series(
            $db,
            fromClause: 'time_entry',
            spacePredicate: 'space_id = :space',
            dateExpr: 'started_at',
            valueExpr: 'duration_seconds',
            currencyExpr: null,
            spaceId: (string) $space->getId(),
            from: $from,
            to: $to,
            interval: $interval,
            extraWhere: 'billable = true',
        );
    }
}
