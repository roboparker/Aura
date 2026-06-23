<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

/**
 * Produces date / time / datetime values. Same-subtype and date↔datetime
 * are the meaningful moves; time has no date component (and vice versa),
 * so those crossings fail. Text is parsed leniently.
 */
final class DateValueConverter implements CustomFieldTypeConverterInterface
{
    public function __construct(private readonly ValueCoercions $coercions)
    {
    }

    public function targetKind(): string
    {
        return 'date';
    }

    public function convert(mixed $value, ConversionContext $context): array
    {
        $fromKind = $context->fromKind;
        $fromSubtype = $context->fromSubtype;
        $toSubtype = $context->toSubtype;

        if ('date' === $fromKind) {
            if ('time' === $fromSubtype && 'time' !== $toSubtype) {
                return [false, null];
            }
            if ('time' === $toSubtype && 'date' === $fromSubtype) {
                return [false, null];
            }
        } elseif ('text' !== $fromKind) {
            return [false, null];
        }

        $parsed = $this->coercions->parseDate($value, $fromKind, $fromSubtype);
        if (null === $parsed) {
            return [false, null];
        }

        $out = match ($toSubtype) {
            'date' => $parsed->format('Y-m-d'),
            'time' => $parsed->format('H:i'),
            'datetime' => $parsed->format('Y-m-d\TH:i:sP'),
            default => null,
        };

        return null === $out ? [false, null] : [true, $out];
    }
}
