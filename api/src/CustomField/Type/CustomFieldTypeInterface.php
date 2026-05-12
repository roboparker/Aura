<?php

declare(strict_types=1);

namespace App\CustomField\Type;

use App\Entity\CustomFieldDefinition;

/**
 * Strategy for a single (kind, subtype) custom-field type. Each concrete
 * implementation owns its config schema, value validation rules,
 * normalization, search-text projection, multi-value support, and which
 * aggregations the project footer can offer.
 *
 * Implementations are auto-tagged `app.custom_field_type` via
 * `_instanceof` in services.yaml and resolved by `kind.subtype` key
 * through {@see App\CustomField\CustomFieldTypeRegistry}.
 *
 * Adding a new type = drop one class implementing this interface; the
 * tag picks it up, the registry exposes it, the validator + footer +
 * API surfaces dispatch through it automatically.
 */
interface CustomFieldTypeInterface
{
    /**
     * Top-level family. Drives PWA editor dispatch and FTS projection.
     * One of: boolean, text, numeric, date, select, reference.
     */
    public function kind(): string;

    /**
     * Specialisation within the kind (e.g. 'money' under 'numeric').
     */
    public function subtype(): string;

    /**
     * Composite identifier `<kind>.<subtype>` — registry lookup key and
     * what gets stored as the `key` returned in API responses.
     */
    public function key(): string;

    /**
     * Violations on a definition's `config` payload. Empty list = config
     * is well-formed. Each violation carries a relative path under
     * `config.*` so the caller can attach it via Symfony's
     * ConstraintViolationBuilder.
     *
     * @param array<string, mixed> $config
     * @return list<TypeViolation>
     */
    public function validateConfig(array $config): array;

    /**
     * Violations on a single value under this definition. Null values
     * are policed by the host validator (required vs nullable on the
     * definition); strategies only check shape + content.
     *
     * @param array<string, mixed> $config
     * @return list<TypeViolation>
     */
    public function validateValue(mixed $value, array $config, CustomFieldDefinition $definition): array;

    /**
     * Canonical storage form — applied on persist so different inputs
     * for the same logical value (e.g. trailing whitespace, dropped
     * `+00:00` on dates) all round-trip identically. Null in, null out.
     *
     * @param array<string, mixed> $config
     */
    public function normalize(mixed $value, array $config): mixed;

    /**
     * Plain-text projection for the FTS index. Null/empty means the
     * value contributes nothing searchable. Reference strategies
     * dereference the IRI and emit the target's display label so users
     * can search by name rather than UUID.
     *
     * @param array<string, mixed> $config
     */
    public function searchText(mixed $value, array $config): ?string;

    /**
     * Whether the type can hold a list (config.multi = true) in addition
     * to a single value. Booleans + numeric.money are deliberately
     * single-only; everything else supports both shapes.
     */
    public function supportsMulti(): bool;

    /**
     * Aggregations the footer can render. Empty array = the field never
     * shows a footer (e.g. text, references). Numeric kinds expose
     * sum/avg/min/max; everything supports count-of-non-null.
     *
     * @return list<value-of<\App\CustomField\Footer\FooterKind::CASES>>
     */
    public function supportedAggregations(): array;
}
