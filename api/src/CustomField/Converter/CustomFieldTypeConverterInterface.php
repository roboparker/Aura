<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

/**
 * Converts a single stored scalar into a candidate value for one target
 * field kind. Keyed by {@see targetKind()} — there is exactly one
 * converter per destination kind (boolean, text, numeric, date, select,
 * reference), and it knows how to build its own type from the various
 * source kinds.
 *
 * Implementations are deliberately *lenient*: they return a best-effort
 * candidate and leave final policing to the target type's own
 * normalize() + validateValue(), run by
 * {@see \App\CustomField\CustomFieldValueConverter}. So a converter need
 * only answer "can this source scalar plausibly become my kind, and if so
 * as what" — bounds, option membership, reference scope, URL shape, etc.
 * are validated downstream.
 *
 * Implementations are auto-tagged `app.custom_field_converter` via
 * `_instanceof` in services.yaml and resolved through
 * {@see CustomFieldConverterRegistry}.
 */
interface CustomFieldTypeConverterInterface
{
    /**
     * The destination kind this converter produces — one of
     * boolean|text|numeric|date|select|reference.
     */
    public function targetKind(): string;

    /**
     * @return array{0: bool, 1: mixed} [true, <candidate>] when the scalar
     *   can become this kind, or [false, null] when it can't.
     */
    public function convert(mixed $value, ConversionContext $context): array;
}
