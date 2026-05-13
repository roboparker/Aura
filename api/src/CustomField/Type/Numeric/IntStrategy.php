<?php

declare(strict_types=1);

namespace App\CustomField\Type\Numeric;

use App\CustomField\Type\TypeViolation;

/**
 * `numeric.int` — whole numbers. Stored as PHP int in the JSON value
 * column so PG's `jsonb_extract_path_text` round-trips them as integer
 * literals without trailing `.0` (which would break exact-equality
 * filters in clients).
 *
 * `decimalPlaces` is rejected — integers don't have any.
 */
final class IntStrategy extends AbstractNumericStrategy
{
    public function subtype(): string
    {
        return 'int';
    }

    public function validateConfig(array $config): array
    {
        $violations = parent::validateConfig($config);
        if (array_key_exists('decimalPlaces', $config)) {
            $violations[] = new TypeViolation(
                'config.decimalPlaces',
                'decimalPlaces is not supported for int fields.',
            );
        }
        // int bounds must themselves be integers — a min of 1.5 on an
        // int field is incoherent.
        foreach (['min', 'max'] as $key) {
            $bound = $config[$key] ?? null;
            if (null !== $bound && !is_int($bound)) {
                $violations[] = new TypeViolation('config.' . $key, sprintf('%s must be an integer for int fields.', $key));
            }
        }
        return $violations;
    }

    protected function validateScalar(mixed $value, array $config): array
    {
        if (!is_int($value)) {
            return [new TypeViolation('', 'Expected an integer.')];
        }

        $violations = [];
        $min = $config['min'] ?? null;
        if (is_int($min) && $value < $min) {
            $violations[] = new TypeViolation('', sprintf('Value cannot be less than %d.', $min));
        }
        $max = $config['max'] ?? null;
        if (is_int($max) && $value > $max) {
            $violations[] = new TypeViolation('', sprintf('Value cannot be greater than %d.', $max));
        }
        return $violations;
    }
}
