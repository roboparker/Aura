<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

/**
 * Lookup table over the tagged `app.custom_field_converter` services,
 * keyed by the destination kind each one produces. Boots from a
 * `!tagged_iterator`, so adding a converter for a new kind is wiring-free.
 */
final class CustomFieldConverterRegistry
{
    /** @var array<string, CustomFieldTypeConverterInterface> */
    private array $byKind = [];

    /**
     * @param iterable<CustomFieldTypeConverterInterface> $converters
     */
    public function __construct(iterable $converters)
    {
        foreach ($converters as $converter) {
            $kind = $converter->targetKind();
            if (isset($this->byKind[$kind])) {
                throw new \LogicException(sprintf(
                    'Duplicate custom-field converter for kind "%s"; both %s and %s claim it.',
                    $kind,
                    $this->byKind[$kind]::class,
                    $converter::class,
                ));
            }
            $this->byKind[$kind] = $converter;
        }
    }

    public function has(string $kind): bool
    {
        return isset($this->byKind[$kind]);
    }

    public function get(string $kind): CustomFieldTypeConverterInterface
    {
        return $this->byKind[$kind]
            ?? throw new \InvalidArgumentException(sprintf('No custom-field converter for kind "%s".', $kind));
    }
}
