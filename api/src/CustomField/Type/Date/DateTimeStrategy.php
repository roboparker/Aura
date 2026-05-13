<?php

declare(strict_types=1);

namespace App\CustomField\Type\Date;

use App\CustomField\Type\TypeViolation;

/**
 * `date.datetime` — point in time, ISO-8601 with timezone offset
 * (`Y-m-d\TH:i:sP` — e.g. `2026-05-12T14:30:00+02:00`). Always include
 * an explicit offset; "naked" local timestamps make cross-region
 * comparisons ambiguous and are a recurring source of off-by-an-hour
 * bugs.
 *
 * The shape mirrors what Symfony's serializer emits for
 * `DateTimeImmutable`, so PWA round-trips with `Date.toISOString()`
 * are clean.
 */
final class DateTimeStrategy extends AbstractDateStrategy
{
    public function subtype(): string
    {
        return 'datetime';
    }

    protected function format(): string
    {
        return 'Y-m-d\TH:i:sP';
    }

    protected function validateScalar(mixed $value, array $config): array
    {
        if (!is_string($value)) {
            return parent::validateScalar($value, $config);
        }

        // Accept the ATOM / RFC-3339 shapes JavaScript's
        // `toISOString()` emits (`2026-05-12T14:30:00.000Z`) by
        // tolerating both fractional seconds and the `Z` shorthand for
        // `+00:00` before passing to the strict format check.
        $normalized = $this->normalizeIsoForParse($value);
        if (false === \DateTimeImmutable::createFromFormat('!' . $this->format(), $normalized)) {
            return [new TypeViolation('', 'Value is not a valid ISO-8601 datetime with timezone offset.')];
        }

        return parent::validateScalar($normalized, $config);
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if (null === $value) {
            return null;
        }
        if ($this->isMulti($config)) {
            if (!is_array($value)) {
                return $value;
            }
            return array_map(fn ($v) => is_string($v) ? $this->normalizeIsoForParse($v) : $v, $value);
        }
        return is_string($value) ? $this->normalizeIsoForParse($value) : $value;
    }

    private function normalizeIsoForParse(string $value): string
    {
        // Strip an optional fractional-second suffix (`.123`) — PHP's
        // strict format doesn't accept it but JS's toISOString always
        // emits it.
        $value = preg_replace('/\.\d+(?=(Z|[+-]\d{2}:?\d{2})$)/', '', $value) ?? $value;
        // `Z` -> `+00:00` so the strict offset format matches.
        if (str_ends_with($value, 'Z')) {
            $value = substr($value, 0, -1) . '+00:00';
        }
        return $value;
    }
}
