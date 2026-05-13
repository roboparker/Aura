<?php

declare(strict_types=1);

namespace App\CustomField\Type\Date;

/**
 * `date.date` — calendar date in `YYYY-MM-DD` (ISO-8601, no time
 * component). The legacy `type='date'` rows backfill to this subtype.
 */
final class DateStrategy extends AbstractDateStrategy
{
    public function subtype(): string
    {
        return 'date';
    }

    protected function format(): string
    {
        return 'Y-m-d';
    }
}
