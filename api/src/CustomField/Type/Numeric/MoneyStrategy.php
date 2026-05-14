<?php

declare(strict_types=1);

namespace App\CustomField\Type\Numeric;

use App\CustomField\CustomFieldKind;
use App\CustomField\Footer\FooterKind;
use App\CustomField\Type\AbstractTypeStrategy;
use App\CustomField\Type\TypeViolation;
use App\Entity\CustomFieldDefinition;
use Symfony\Component\Intl\Currencies;

/**
 * `numeric.money` — currency-aware monetary amount. Storage shape is
 * `{amount: int, currency: ISO-4217}`:
 *
 *   - `amount` is in MINOR UNITS (cents for USD, pence for GBP, fils
 *     for KWD, …) so the wire is integer-only and we never accumulate
 *     IEEE-754 rounding drift across a project's task list. Clients do
 *     their own minor↔major conversion based on the currency's
 *     fraction-digits (Intl knows this).
 *   - `currency` is the ISO-4217 code (uppercased on normalize) — gold
 *     standard for cross-system interop and validated via
 *     {@see Currencies::exists()} so unknown codes can't sneak in.
 *
 * Config: `{min?, max?, currency, multi: false}` — `currency` is
 * REQUIRED at field-creation time so every value in the column is
 * commensurate (you can't average USD with EUR). Multi is deliberately
 * disabled: a "list of money values" use case is rare and conflates
 * with the project-footer sum/avg aggregation. If a real use case
 * surfaces, lift the restriction in a follow-up rather than papering
 * over it here.
 *
 * Footer: sum/avg/min/max/count — same set as int/float because the
 * minor-unit integer is straightforwardly aggregable.
 */
final class MoneyStrategy extends AbstractTypeStrategy
{
    public function kind(): string
    {
        return CustomFieldKind::NUMERIC->value;
    }

    public function subtype(): string
    {
        return 'money';
    }

    public function supportsMulti(): bool
    {
        return false;
    }

    /**
     * @return list<value-of<\App\CustomField\Footer\FooterKind>>
     */
    public function supportedAggregations(): array
    {
        return [
            FooterKind::SUM->value,
            FooterKind::AVG->value,
            FooterKind::MIN->value,
            FooterKind::MAX->value,
            FooterKind::COUNT->value,
        ];
    }

    public function validateConfig(array $config): array
    {
        $violations = $this->validateMultiConfig($config);

        $currency = $config['currency'] ?? null;
        if (null === $currency || !is_string($currency) || '' === $currency) {
            $violations[] = new TypeViolation('config.currency', 'currency is required.');
        } elseif (!Currencies::exists(strtoupper($currency))) {
            $violations[] = new TypeViolation('config.currency', sprintf('Unknown currency code "%s".', $currency));
        }

        foreach (['min', 'max'] as $key) {
            $bound = $config[$key] ?? null;
            if (null !== $bound && !is_int($bound)) {
                $violations[] = new TypeViolation(
                    'config.' . $key,
                    sprintf('%s must be an integer (minor units).', $key),
                );
            }
        }

        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;
        if (is_int($min) && is_int($max) && $min > $max) {
            $violations[] = new TypeViolation('config.min', 'min cannot exceed max.');
        }

        return $violations;
    }

    public function validateValue(mixed $value, array $config, CustomFieldDefinition $definition): array
    {
        if (!is_array($value)) {
            return [new TypeViolation('', 'Expected an object with amount and currency.')];
        }

        $violations = [];

        $amount = $value['amount'] ?? null;
        if (!is_int($amount)) {
            $violations[] = new TypeViolation('amount', 'amount must be an integer (minor units).');
        } else {
            $min = $config['min'] ?? null;
            if (is_int($min) && $amount < $min) {
                $violations[] = new TypeViolation('amount', sprintf('amount cannot be less than %d.', $min));
            }
            $max = $config['max'] ?? null;
            if (is_int($max) && $amount > $max) {
                $violations[] = new TypeViolation('amount', sprintf('amount cannot be greater than %d.', $max));
            }
        }

        $valueCurrency = $value['currency'] ?? null;
        $configCurrency = is_string($config['currency'] ?? null)
            ? strtoupper($config['currency'])
            : null;
        if (!is_string($valueCurrency) || '' === $valueCurrency) {
            $violations[] = new TypeViolation('currency', 'currency is required.');
        } elseif (!Currencies::exists(strtoupper($valueCurrency))) {
            $violations[] = new TypeViolation('currency', sprintf('Unknown currency code "%s".', $valueCurrency));
        } elseif (null !== $configCurrency && strtoupper($valueCurrency) !== $configCurrency) {
            // Field is pinned to a single currency; a value in a
            // different one would break sum/avg aggregations downstream.
            $violations[] = new TypeViolation('currency', sprintf(
                'Value currency "%s" does not match the field currency "%s".',
                $valueCurrency,
                $configCurrency,
            ));
        }

        return $violations;
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $amount = $value['amount'] ?? null;
        $currency = $value['currency'] ?? null;
        return [
            'amount' => is_numeric($amount) ? (int) $amount : $amount,
            'currency' => is_string($currency) ? strtoupper($currency) : $currency,
        ];
    }

    public function searchText(mixed $value, array $config): ?string
    {
        if (!is_array($value)) {
            return null;
        }
        $amount = $value['amount'] ?? null;
        $currency = $value['currency'] ?? null;
        if (!is_int($amount) || !is_string($currency)) {
            return null;
        }
        // Emit "amount CCY" so FTS hits work for "USD 100" or "100 USD"
        // queries via the `websearch_to_tsquery` parser.
        return $amount . ' ' . strtoupper($currency);
    }
}
