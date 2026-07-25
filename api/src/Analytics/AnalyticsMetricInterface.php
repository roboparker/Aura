<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Entity\Space;
use Doctrine\DBAL\Connection;

/**
 * One metric on the analytics dashboard (#analytics).
 *
 * Implementations are auto-registered via the `app.analytics_metric` tag, so
 * adding a metric is a one-class drop — the same shape as
 * {@see \App\CustomField\Type\CustomFieldTypeInterface} and
 * {@see \App\Mcp\Tool\McpToolInterface}.
 *
 * Two rules every metric must honour:
 *
 *  1. **Aggregate in SQL.** Hydrating rows to sum them in PHP turns a dashboard
 *     into an N+1 the moment a space has real data.
 *  2. **Declare the permission category you read.** The controller gates each
 *     metric independently, so a member who may see time but not money gets the
 *     hours charts and none of the revenue ones.
 */
interface AnalyticsMetricInterface
{
    /** Stable identifier used on the wire, e.g. `invoiced`. */
    public function key(): string;

    /** Human label for the chart card. */
    public function label(): string;

    /**
     * The {@see \App\Security\Permission\SpacePermission} category this metric
     * reads. `invoices` is admin-reserved, so metrics declaring it are hidden
     * from ordinary members — see AnalyticsController::allows().
     */
    public function permissionCategory(): string;

    /**
     * `money` (minor units, carries a currency) or `seconds` (carries none).
     * The client formats; the server never returns pre-formatted strings,
     * because a number that has to be parsed back is a number waiting to be
     * parsed wrong.
     */
    public function unit(): string;

    /**
     * One entry per currency (money) or exactly one with `currency: null`
     * (seconds). Buckets missing from the result are absent, not zero — the
     * caller fills gaps, since only it knows the full period axis.
     *
     * @param 'day'|'week'|'month' $interval
     *
     * @return list<array{currency: string|null, points: list<array{period: string, value: int}>}>
     */
    public function series(
        Connection $db,
        Space $space,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $interval,
    ): array;
}
