<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

/**
 * Produces text values by stringifying (almost) any source scalar. The
 * url subtype's stricter shape is enforced downstream by the text
 * strategy's validateValue(), so a non-URL string simply fails to survive
 * the conversion to a url field.
 */
final class TextValueConverter implements CustomFieldTypeConverterInterface
{
    public function __construct(private readonly ValueCoercions $coercions)
    {
    }

    public function targetKind(): string
    {
        return 'text';
    }

    public function convert(mixed $value, ConversionContext $context): array
    {
        $string = $this->coercions->stringify(
            $value,
            $context->fromKind,
            $context->fromSubtype,
            $context->fromConfig,
        );

        return null === $string ? [false, null] : [true, $string];
    }
}
