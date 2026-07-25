<?php

declare(strict_types=1);

namespace App\Analytics\Metric;

use App\Analytics\AnalyticsMetricInterface;
use App\Analytics\AnalyticsQuery;
use App\Entity\Space;
use App\Security\Permission\SpacePermission;
use Doctrine\DBAL\Connection;

/**
 * Work in progress: the value of billable time that hasn't been invoiced yet
 * (`billed_at IS NULL`), bucketed by when it was worked.
 *
 * This is money already earned but not yet claimed — the number that tells a
 * freelancer they're overdue to raise an invoice. It shrinks as entries get
 * pulled onto invoices, so an old bucket that stays high is the signal.
 *
 * Value = seconds ÷ 3600 × the entry's **snapshotted** rate, matching how
 * invoice generation prices the same entry. Rounded per row like the invoice
 * line, so the dashboard and the eventual invoice agree. Entries with no rate
 * contribute nothing rather than counting as free work.
 *
 * Rides `time_entries` rather than `invoices`: it's derived from the tracked
 * time its owner can already see, not from any issued document.
 */
final class UnbilledValueMetric implements AnalyticsMetricInterface
{
    public function key(): string
    {
        return 'unbilled_value';
    }

    public function label(): string
    {
        return 'Unbilled value';
    }

    public function permissionCategory(): string
    {
        return SpacePermission::TIME_ENTRIES;
    }

    public function unit(): string
    {
        return 'money';
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
            valueExpr: 'ROUND(ROUND(duration_seconds / 3600.0, 2) * rate_amount)',
            currencyExpr: 'rate_currency',
            spaceId: (string) $space->getId(),
            from: $from,
            to: $to,
            interval: $interval,
            extraWhere: 'billable = true AND billed_at IS NULL AND rate_amount IS NOT NULL',
        );
    }
}
