<?php

declare(strict_types=1);

namespace App\CustomField;

use App\CustomField\Converter\ConversionContext;
use App\CustomField\Converter\CustomFieldConverterRegistry;
use App\Entity\CustomFieldDefinition;

/**
 * Orchestrates best-effort conversion of a stored
 * {@see \App\Entity\CustomFieldValue} value when a definition's
 * (kind, subtype, config) changes — so editing a field "Integer" →
 * "Money", or toggling single ↔ multi, keeps the data instead of silently
 * orphaning it.
 *
 * This class owns the envelope; the per-kind shaping rules live in the
 * tagged {@see \App\CustomField\Converter\CustomFieldTypeConverterInterface}
 * services resolved through {@see CustomFieldConverterRegistry} (one per
 * destination kind). The flow is lenient in, strict out:
 *
 *   1. decompose the value into scalar elements (single or multi);
 *   2. ask the target kind's converter to shape each element — anything
 *      it can't represent is dropped;
 *   3. reassemble into the target's single/multi shape (de-duping the
 *      member-unique kinds);
 *   4. run the candidate through the **target type strategy's own
 *      normalize() + validateValue()** — only values the target would
 *      actually accept survive.
 *
 * A value that ends up with nothing is reported unconvertible so the
 * caller can confirm deletion.
 */
final class CustomFieldValueConverter
{
    public function __construct(
        private readonly CustomFieldTypeRegistry $types,
        private readonly CustomFieldConverterRegistry $converters,
    ) {
    }

    /**
     * @return array{0: bool, 1: mixed} [true, <converted value>] when the
     *   value survives (possibly reshaped), or [false, null] when it can't
     *   be represented in the target type and should be dropped.
     */
    public function convert(mixed $value, CustomFieldDefinition $from, CustomFieldDefinition $to): array
    {
        if (null === $value) {
            return [true, null];
        }

        $toKey = $to->getTypeKey();
        $toKind = $to->getKind();
        if (!$this->types->has($toKey) || !$this->converters->has($toKind)) {
            return [false, null];
        }

        $converter = $this->converters->get($toKind);
        $context = new ConversionContext(
            $from->getKind(),
            $from->getSubtype(),
            $from->getConfig(),
            $to->getSubtype(),
            $to->getConfig(),
        );

        $elements = ($this->isMulti($from) && is_array($value)) ? array_values($value) : [$value];

        $converted = [];
        foreach ($elements as $element) {
            if (null === $element) {
                continue;
            }
            [$ok, $candidate] = $converter->convert($element, $context);
            if ($ok) {
                $converted[] = $candidate;
            }
        }

        if ([] === $converted) {
            return [false, null];
        }

        if ($this->isMulti($to)) {
            // Selects + references reject duplicate members; numeric/text
            // multis legitimately allow repeats, so only de-dupe the former.
            if ('select' === $toKind || 'reference' === $toKind) {
                $converted = array_values(array_unique($converted, SORT_REGULAR));
            }
            $candidate = $converted;
        } else {
            $candidate = $converted[0];
        }

        $strategy = $this->types->get($toKey);
        $normalized = $strategy->normalize($candidate, $to->getConfig());
        if ([] !== $strategy->validateValue($normalized, $to->getConfig(), $to)) {
            return [false, null];
        }

        return [true, $normalized];
    }

    /**
     * Whether a definition stores a list rather than a single value.
     */
    public function isMulti(CustomFieldDefinition $def): bool
    {
        $kind = $def->getKind();
        $subtype = $def->getSubtype();
        if ('select' === $kind) {
            return 'multi' === $subtype;
        }
        if ('boolean' === $kind) {
            return false;
        }
        if ('numeric' === $kind && 'money' === $subtype) {
            return false;
        }

        return true === ($def->getConfig()['multi'] ?? false);
    }
}
