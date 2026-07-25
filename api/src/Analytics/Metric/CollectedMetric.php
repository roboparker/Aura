<?php

declare(strict_types=1);

namespace App\Analytics\Metric;

use App\Analytics\AnalyticsMetricInterface;
use App\Analytics\AnalyticsQuery;
use App\Entity\Space;
use App\Security\Permission\SpacePermission;
use Doctrine\DBAL\Connection;

/**
 * Cash actually collected per period, by payment date.
 *
 * The counterpart to {@see InvoicedMetric}: invoiced is what was *claimed*,
 * collected is what *arrived*. The gap between the two curves is the story a
 * freelancer most needs to see.
 *
 * `invoice_payment` has no space of its own, so it inherits one by joining its
 * invoice; the currency likewise comes from the invoice.
 */
final class CollectedMetric implements AnalyticsMetricInterface
{
    public function key(): string
    {
        return 'collected';
    }

    public function label(): string
    {
        return 'Collected';
    }

    public function permissionCategory(): string
    {
        return SpacePermission::INVOICES;
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
            fromClause: 'invoice_payment p JOIN invoice i ON i.id = p.invoice_id',
            spacePredicate: 'i.space_id = :space',
            dateExpr: 'p.paid_on',
            valueExpr: 'p.amount',
            currencyExpr: 'i.currency',
            spaceId: (string) $space->getId(),
            from: $from,
            to: $to,
            interval: $interval,
        );
    }
}
