<?php

declare(strict_types=1);

namespace App\CustomField\Type\Date;

use App\CustomField\Type\TypeViolation;

/**
 * `date.time` — wall-clock time of day, `HH:MM` (24-hour, leading
 * zeros). No date component, no seconds (the PWA's time picker only
 * speaks minutes; if a real per-second use case surfaces, lift the
 * format to `H:i:s` and grandfather existing rows by accepting either
 * via a second createFromFormat fallback).
 *
 * Storage is a plain string so DQL ordering stays lexicographic-equals-
 * chronological without timezone gymnastics.
 */
final class TimeStrategy extends AbstractDateStrategy
{
    public function subtype(): string
    {
        return 'time';
    }

    protected function format(): string
    {
        return 'H:i';
    }

    protected function validateScalar(mixed $value, array $config): array
    {
        // Strict format ('!H:i') with no leading zero is rejected by
        // createFromFormat — so '9:30' fails validation. That's a
        // surprise to clients used to free-form parsing; surface a
        // clearer message than the abstract base's generic one.
        if (is_string($value) && 1 !== preg_match('/^\d{2}:\d{2}$/', $value)) {
            return [new TypeViolation('', 'Time must use the HH:MM format (24-hour, leading zeros).')];
        }
        return parent::validateScalar($value, $config);
    }
}
