<?php

declare(strict_types=1);

namespace App\CustomField;

use App\CustomField\Type\CustomFieldTypeInterface;

/**
 * Lookup table over the tagged `app.custom_field_type` services. Resolves
 * a definition's `<kind>.<subtype>` key to the strategy that owns
 * validation, normalization, FTS projection, and footer aggregations
 * for that type.
 *
 * Boots from a `!tagged_iterator` so adding a new strategy is wiring-free
 * — implementing {@see CustomFieldTypeInterface} is the entire surface.
 */
final class CustomFieldTypeRegistry
{
    /** @var array<string, CustomFieldTypeInterface> */
    private array $byKey = [];

    /** @var array<string, list<CustomFieldTypeInterface>> */
    private array $byKind = [];

    /**
     * @param iterable<CustomFieldTypeInterface> $types
     */
    public function __construct(iterable $types)
    {
        foreach ($types as $type) {
            $key = $type->key();
            if (isset($this->byKey[$key])) {
                throw new \LogicException(sprintf(
                    'Duplicate custom-field type "%s"; both %s and %s claim it.',
                    $key,
                    $this->byKey[$key]::class,
                    $type::class,
                ));
            }
            $this->byKey[$key] = $type;
            $this->byKind[$type->kind()][] = $type;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    public function get(string $key): CustomFieldTypeInterface
    {
        return $this->byKey[$key]
            ?? throw new \InvalidArgumentException(sprintf('Unknown custom-field type "%s".', $key));
    }

    /**
     * @return list<CustomFieldTypeInterface>
     */
    public function forKind(string $kind): array
    {
        return $this->byKind[$kind] ?? [];
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }

    /**
     * @return array<string, CustomFieldTypeInterface>
     */
    public function all(): array
    {
        return $this->byKey;
    }
}
