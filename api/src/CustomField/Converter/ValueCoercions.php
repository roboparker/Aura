<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

use Symfony\Component\Intl\Currencies;

/**
 * Shared primitives for reading a source scalar as the building blocks
 * the destination converters need — a number, a plain string, a parsed
 * date — plus the currency / select-option helpers those readings lean
 * on. Stateless; concentrating the source-side rules here keeps each
 * {@see CustomFieldTypeConverterInterface} focused on shaping its own
 * target type.
 */
final class ValueCoercions
{
    /**
     * Read a scalar as a float for numeric/boolean targets, or null when
     * it has no numeric meaning. Money divides by the currency's fraction
     * digits to its major-unit value.
     *
     * @param array<string, mixed> $fromConfig
     */
    public function toNumber(mixed $value, string $fromKind, string $fromSubtype, array $fromConfig): ?float
    {
        if ('numeric' === $fromKind) {
            if ('money' === $fromSubtype) {
                if (!is_array($value)) {
                    return null;
                }
                $amount = $value['amount'] ?? null;
                $currency = $value['currency'] ?? null;
                if (!is_int($amount) || !is_string($currency)) {
                    return null;
                }

                return $amount / (10 ** $this->fractionDigits($currency));
            }
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            return null;
        }
        if ('boolean' === $fromKind) {
            if (true === $value) {
                return 1.0;
            }

            return false === $value ? 0.0 : null;
        }
        if ('text' === $fromKind && is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * Render a scalar as plain text for text targets, or null when there's
     * nothing meaningful to show. Money formats to "amount CCY", booleans
     * to Yes/No, selects to their option label.
     *
     * @param array<string, mixed> $fromConfig
     */
    public function stringify(mixed $value, string $fromKind, string $fromSubtype, array $fromConfig): ?string
    {
        switch ($fromKind) {
            case 'text':
            case 'date':
            case 'reference':
                return is_string($value) ? $value : null;
            case 'boolean':
                if (true === $value) {
                    return 'Yes';
                }

                return false === $value ? 'No' : null;
            case 'numeric':
                if ('money' === $fromSubtype) {
                    if (!is_array($value)) {
                        return null;
                    }
                    $amount = $value['amount'] ?? null;
                    $currency = $value['currency'] ?? null;
                    if (!is_int($amount) || !is_string($currency)) {
                        return null;
                    }
                    $digits = $this->fractionDigits($currency);

                    return number_format($amount / (10 ** $digits), $digits, '.', '')
                        . ' ' . strtoupper($currency);
                }
                if (is_int($value)) {
                    return (string) $value;
                }

                return is_float($value) ? $this->floatToString($value) : null;
            case 'select':
                if (!is_string($value)) {
                    return null;
                }

                return $this->selectLabels($fromConfig)[$value] ?? $value;
        }

        return null;
    }

    /**
     * Parse a source scalar into a date. Date kinds use their strict wire
     * format; text falls back to lenient parsing.
     */
    public function parseDate(mixed $value, string $fromKind, string $fromSubtype): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        if ('date' === $fromKind) {
            $format = match ($fromSubtype) {
                'date' => 'Y-m-d',
                'time' => 'H:i',
                default => 'Y-m-d\TH:i:sP',
            };
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);

            return false === $parsed ? null : $parsed;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function fractionDigits(string $currency): int
    {
        $currency = strtoupper($currency);

        return Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;
    }

    public function floatToString(float $value): string
    {
        $string = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return '' === $string ? '0' : $string;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public function selectKeys(array $config): array
    {
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }
        $keys = [];
        foreach ($options as $option) {
            if (is_array($option) && is_string($key = $option['key'] ?? null)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public function selectLabels(array $config): array
    {
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }
        $labels = [];
        foreach ($options as $option) {
            if (
                is_array($option)
                && is_string($key = $option['key'] ?? null)
                && is_string($label = $option['label'] ?? null)
            ) {
                $labels[$key] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function keyForLabel(array $config, string $label): ?string
    {
        $needle = strtolower(trim($label));
        foreach ($this->selectLabels($config) as $key => $optionLabel) {
            if (strtolower(trim($optionLabel)) === $needle) {
                return $key;
            }
        }

        return null;
    }
}
