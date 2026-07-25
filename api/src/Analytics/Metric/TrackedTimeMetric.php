<?php

declare(strict_types=1);

namespace App\Analytics\Metric;

use App\Analytics\AnalyticsMetricInterface;
use App\Analytics\AnalyticsQuery;
use App\Entity\Space;
use App\Security\Permission\SpacePermission;
use Doctrine\DBAL\Connection;

/**
 * All time tracked per period, billable or not.
 *
 * Paired with {@see BillableTimeMetric} this gives utilisation — which is
 * deliberately *not* its own metric, because a ratio of two series the client
 * already has is arithmetic, not a database query.
 *
 * Running timers (no `ended_at`, so no duration yet) contribute nothing, which
 * is what you want: an in-flight timer isn't measured work.
 */
final class TrackedTimeMetric implements AnalyticsMetricInterface
{
    public function key(): string
    {
        return 'tracked_time';
    }

    public function label(): string
    {
        return 'Time tracked';
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
        );
    }
}
