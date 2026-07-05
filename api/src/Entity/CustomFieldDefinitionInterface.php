<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Uuid;

/**
 * Shared contract for the two custom-field definition sources: the
 * space-owned {@see CustomFieldDefinition} (#custom-fields-space) and the
 * instance-wide, admin-managed {@see GlobalCustomFieldDefinition}.
 *
 * Both speak the same (kind, subtype, config, footer, nullable) shape and
 * resolve to the same {@see App\CustomField\Type\CustomFieldTypeInterface}
 * strategy through the registry, so validation, normalization, FTS
 * boardion, and footer aggregation are written once against this
 * interface and reused for either source. A {@see CustomFieldValue} can
 * therefore point at either definition (a nullable second FK + XOR) and
 * every consumer reaches its live definition through
 * {@see CustomFieldValue::getEffectiveDefinition()}.
 *
 * `getSpace()` returns null for global definitions — they are not scoped
 * to any space. The only strategy that reads it is the reference family,
 * which is why global fields disallow the `reference` kind in v1.
 */
interface CustomFieldDefinitionInterface
{
    public function getId(): ?Uuid;

    public function getName(): string;

    /** Top-level family — one of {@see App\CustomField\CustomFieldKind}'s values. */
    public function getKind(): string;

    /** Specialisation within the kind (e.g. `money` under `numeric`). */
    public function getSubtype(): string;

    /** Composite `<kind>.<subtype>` registry lookup key. */
    public function getTypeKey(): string;

    /** @return array<string, mixed> */
    public function getConfig(): array;

    /** @return array{kind: string, label?: string}|null */
    public function getFooter(): ?array;

    public function isNullable(): bool;

    /** Sort position within its manager's list. */
    public function getPosition(): int;

    /** The owning space, or null for instance-wide global definitions. */
    public function getSpace(): ?Space;

    /** Default surface visibility (`list` / `board` / `both` / comma-joined set). */
    public function getVisibility(): string;
}
