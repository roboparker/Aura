<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

/**
 * Produces select option keys. A key already valid in the target options
 * passes straight through; otherwise we bridge by matching the human
 * label (so re-keyed option sets keep their picks). Text is matched
 * against the target keys, then labels.
 */
final class SelectValueConverter implements CustomFieldTypeConverterInterface
{
    public function __construct(private readonly ValueCoercions $coercions)
    {
    }

    public function targetKind(): string
    {
        return 'select';
    }

    public function convert(mixed $value, ConversionContext $context): array
    {
        if (!is_string($value)) {
            return [false, null];
        }

        $toKeys = $this->coercions->selectKeys($context->toConfig);

        if ('select' === $context->fromKind) {
            if (in_array($value, $toKeys, true)) {
                return [true, $value];
            }
            $label = $this->coercions->selectLabels($context->fromConfig)[$value] ?? null;
            if (is_string($label)) {
                $key = $this->coercions->keyForLabel($context->toConfig, $label);
                if (null !== $key) {
                    return [true, $key];
                }
            }

            return [false, null];
        }

        if ('text' === $context->fromKind) {
            if (in_array($value, $toKeys, true)) {
                return [true, $value];
            }
            $key = $this->coercions->keyForLabel($context->toConfig, $value);
            if (null !== $key) {
                return [true, $key];
            }
        }

        return [false, null];
    }
}
