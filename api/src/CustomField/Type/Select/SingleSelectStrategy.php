<?php

declare(strict_types=1);

namespace App\CustomField\Type\Select;

use App\CustomField\Type\TypeViolation;

/**
 * `select.single` — pick exactly one option from a fixed list. The
 * pre-#227 `type='dropdown'` rows backfill to this subtype, with their
 * flat `string[]` options upgraded to `[{key, label}]` pairs where
 * key=label preserves the historical values.
 *
 * Multi is intentionally disabled — switching a single select to
 * multi-value mid-flight would orphan existing scalar values that
 * the multi consumer expects as lists. Use `select.multi` from day
 * one when a list shape is needed.
 */
final class SingleSelectStrategy extends AbstractSelectStrategy
{
    public function subtype(): string
    {
        return 'single';
    }

    public function supportsMulti(): bool
    {
        return false;
    }

    protected function validateValueAgainstKeys(mixed $value, array $keys, array $config): array
    {
        if (!is_string($value)) {
            return [new TypeViolation('', 'Expected a string key.')];
        }
        if (!in_array($value, $keys, true)) {
            return [new TypeViolation('', sprintf('Value "%s" is not in the allowed options.', $value))];
        }
        return [];
    }
}
