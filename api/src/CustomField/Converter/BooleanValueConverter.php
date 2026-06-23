<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

/**
 * Produces boolean values: identity from boolean, non-zero test from
 * numbers, and the usual truthy/falsey words from text.
 */
final class BooleanValueConverter implements CustomFieldTypeConverterInterface
{
    public function __construct(private readonly ValueCoercions $coercions)
    {
    }

    public function targetKind(): string
    {
        return 'boolean';
    }

    public function convert(mixed $value, ConversionContext $context): array
    {
        if ('boolean' === $context->fromKind) {
            return [true, (bool) $value];
        }

        $number = $this->coercions->toNumber($value, $context->fromKind, $context->fromSubtype, $context->fromConfig);
        if (null !== $number) {
            return [true, 0.0 !== $number];
        }

        if ('text' === $context->fromKind && is_string($value)) {
            $token = strtolower(trim($value));
            if (in_array($token, ['true', '1', 'yes', 'y', 'on'], true)) {
                return [true, true];
            }
            if (in_array($token, ['false', '0', 'no', 'n', 'off'], true)) {
                return [true, false];
            }
        }

        return [false, null];
    }
}
