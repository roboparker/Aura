<?php

declare(strict_types=1);

namespace App\CustomField\Type;

/**
 * Shared helpers for strategies that support both single and multi-value
 * shapes (config.multi). Folds the per-element walk + path bookkeeping
 * into one place so each strategy only writes `validateScalar()` /
 * `normalizeScalar()` and the trait drives the iteration.
 *
 * Concrete strategies declare via {@see CustomFieldTypeInterface::supportsMulti()}
 * whether multi is even on the table; this trait assumes it is and lets
 * the strategy gate at compile-time.
 */
trait MultiValueHelper
{
    /**
     * Walk a value as a list of (path, scalar) pairs. Single-value mode
     * yields one item with path = ''; multi-value mode yields the
     * indexed elements with path = '[i]'.
     *
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    private function walkValues(mixed $value, bool $multi): \Generator
    {
        if (!$multi) {
            yield 0 => ['', $value];
            return;
        }
        if (!is_array($value)) {
            // Multi field but non-array input — caller emits a top-level
            // "expected list" violation; nothing useful to walk.
            return;
        }
        foreach (array_values($value) as $i => $element) {
            yield $i => ['[' . $i . ']', $element];
        }
    }

    /**
     * For multi-value config, ensure the input is a list (not a map and
     * not a bare scalar). Returns a violation if the shape is wrong;
     * null if the value is fine to walk. Single-value mode is a no-op.
     */
    private function ensureMultiShape(mixed $value, bool $multi): ?TypeViolation
    {
        if (!$multi || null === $value) {
            return null;
        }
        if (!is_array($value)) {
            return new TypeViolation('', 'Expected a list of values.');
        }
        // array_is_list rejects associative shapes — clients send plain
        // arrays, not keyed objects, for multi-value fields.
        if ([] !== $value && !array_is_list($value)) {
            return new TypeViolation('', 'Expected a list of values.');
        }
        return null;
    }
}
