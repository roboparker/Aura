<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

/**
 * Produces numeric values. Anything readable as a number becomes an int
 * (rounded to nearest), a float, or money (scaled to the target
 * currency's minor units).
 */
final class NumericValueConverter implements CustomFieldTypeConverterInterface
{
    public function __construct(private readonly ValueCoercions $coercions)
    {
    }

    public function targetKind(): string
    {
        return 'numeric';
    }

    public function convert(mixed $value, ConversionContext $context): array
    {
        $number = $this->coercions->toNumber($value, $context->fromKind, $context->fromSubtype, $context->fromConfig);
        if (null === $number) {
            return [false, null];
        }

        if ('int' === $context->toSubtype) {
            return [true, (int) round($number)];
        }
        if ('float' === $context->toSubtype) {
            return [true, $number];
        }
        if ('money' === $context->toSubtype) {
            $currency = $context->toConfig['currency'] ?? null;
            if (!is_string($currency) || '' === $currency) {
                return [false, null];
            }
            $digits = $this->coercions->fractionDigits($currency);

            return [true, [
                'amount' => (int) round($number * (10 ** $digits)),
                'currency' => strtoupper($currency),
            ]];
        }

        return [false, null];
    }
}
