<?php

declare(strict_types=1);

namespace App\Analytics\Metric;

use App\Analytics\AnalyticsMetricInterface;
use App\Analytics\AnalyticsQuery;
use App\Entity\Invoice;
use App\Entity\Space;
use App\Security\Permission\SpacePermission;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Value invoiced per period, by issue date.
 *
 * Draft and void invoices are excluded: a draft isn't a claim on anyone yet,
 * and a void one was withdrawn — counting either would overstate what the
 * business actually billed.
 */
final class InvoicedMetric implements AnalyticsMetricInterface
{
    public function key(): string
    {
        return 'invoiced';
    }

    public function label(): string
    {
        return 'Invoiced';
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
            fromClause: 'invoice',
            spacePredicate: 'space_id = :space',
            dateExpr: 'issue_date',
            valueExpr: 'total',
            currencyExpr: 'currency',
            spaceId: (string) $space->getId(),
            from: $from,
            to: $to,
            interval: $interval,
            extraWhere: 'status IN (:statuses)',
            extraParams: ['statuses' => [Invoice::STATUS_SENT, Invoice::STATUS_PAID, Invoice::STATUS_OVERDUE]],
            extraTypes: ['statuses' => ArrayParameterType::STRING],
        );
    }
}
