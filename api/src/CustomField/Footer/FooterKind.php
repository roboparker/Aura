<?php

declare(strict_types=1);

namespace App\CustomField\Footer;

/**
 * Footer aggregation kinds. Centralised so strategies declaring
 * `supportedAggregations()`, the definition's `footer.kind` validator,
 * and the SQL aggregator agree on the allow-list.
 */
enum FooterKind: string
{
    case SUM = 'sum';
    case AVG = 'avg';
    case MIN = 'min';
    case MAX = 'max';
    case COUNT = 'count';
    /**
     * Per-option counts for a select field — a `{key, label, count}` list
     * (one entry per configured option). Only select strategies advertise it.
     */
    case BREAKDOWN = 'breakdown';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
