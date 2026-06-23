<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

/**
 * Immutable description of one value-conversion request, handed to a
 * {@see CustomFieldTypeConverterInterface}. Carries the source type's
 * (kind, subtype, config) and the target's (subtype, config) — the target
 * kind is implied by which converter is resolved, so it isn't repeated.
 */
final class ConversionContext
{
    /**
     * @param array<string, mixed> $fromConfig
     * @param array<string, mixed> $toConfig
     */
    public function __construct(
        public readonly string $fromKind,
        public readonly string $fromSubtype,
        public readonly array $fromConfig,
        public readonly string $toSubtype,
        public readonly array $toConfig,
    ) {
    }
}
