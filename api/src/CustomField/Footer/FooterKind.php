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
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
