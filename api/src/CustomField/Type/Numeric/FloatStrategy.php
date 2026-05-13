<?php

declare(strict_types=1);

namespace App\CustomField\Type\Numeric;

use App\CustomField\Type\TypeViolation;

/**
 * `numeric.float` — fractional numbers. Backwards-compatible target for
 * the legacy `number` type: a pre-#227 row with `type='number'` is
 * backfilled to `(numeric, float, {multi:false})` by the migration so
 * existing decimal data survives.
 *
 * `decimalPlaces` (optional) is the maximum number of digits after the
 * decimal point — clients use it to drive the PWA's input step and
 * display formatter. It's a precision hint, not a strict floor.
 */
final class FloatStrategy extends AbstractNumericStrategy
{
    public function subtype(): string
    {
        return 'float';
    }

    public function validateConfig(array $config): array
    {
        $violations = parent::validateConfig($config);

        $places = $config['decimalPlaces'] ?? null;
        if (null !== $places && (!is_int($places) || $places < 0)) {
            $violations[] = new TypeViolation(
                'config.decimalPlaces',
                'decimalPlaces must be a non-negative integer.',
            );
        }

        return $violations;
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
            return array_map(static fn ($v) => is_int($v) ? (float) $v : $v, $value);
        }
        // Coerce int input up to float so `3` and `3.0` round-trip
        // identically — the value column is JSON, which distinguishes
        // the two, and we don't want filter chains to flake on the
        // representation choice.
        return is_int($value) ? (float) $value : $value;
    }

    protected function validateScalar(mixed $value, array $config): array
    {
        if (!is_int($value) && !is_float($value)) {
            return [new TypeViolation('', 'Expected a number.')];
        }

        $violations = [];
        $min = $config['min'] ?? null;
        if ((is_int($min) || is_float($min)) && $value < $min) {
            $violations[] = new TypeViolation('', sprintf('Value cannot be less than %s.', (string) $min));
        }
        $max = $config['max'] ?? null;
        if ((is_int($max) || is_float($max)) && $value > $max) {
            $violations[] = new TypeViolation('', sprintf('Value cannot be greater than %s.', (string) $max));
        }
        return $violations;
    }
}
