<?php

declare(strict_types=1);

namespace App\CustomField\Type\Numeric;

use App\CustomField\CustomFieldKind;
use App\CustomField\Footer\FooterKind;
use App\CustomField\Type\AbstractTypeStrategy;
use App\CustomField\Type\MultiValueHelper;
use App\CustomField\Type\TypeViolation;
use App\Entity\CustomFieldDefinition;

/**
 * Shared validation + normalization for numeric kinds (int, float).
 * Money lives in {@see MoneyStrategy} because its value shape is
 * `{amount, currency}` rather than a bare number — it inherits the
 * `kind()` but overrides everything else.
 *
 * Config: `{min?, max?, decimalPlaces?, multi}`.
 *   - `min` / `max` are inclusive numeric bounds. Either or both may
 *     be set.
 *   - `decimalPlaces` is only meaningful for `numeric.float`; the int
 *     subtype overrides validateConfig to reject it.
 *   - `multi: true` switches the value shape to a list of numbers.
 *
 * Footer: `sum`, `avg`, `min`, `max`, `count`.
 */
abstract class AbstractNumericStrategy extends AbstractTypeStrategy
{
    use MultiValueHelper;

    public function kind(): string
    {
        return CustomFieldKind::NUMERIC->value;
    }

    public function supportsMulti(): bool
    {
        return true;
    }

    /**
     * @return list<string>
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

        $min = $config['min'] ?? null;
        if (null !== $min && !is_int($min) && !is_float($min)) {
            $violations[] = new TypeViolation('config.min', 'min must be a number.');
        }

        $max = $config['max'] ?? null;
        if (null !== $max && !is_int($max) && !is_float($max)) {
            $violations[] = new TypeViolation('config.max', 'max must be a number.');
        }

        if ((is_int($min) || is_float($min)) && (is_int($max) || is_float($max)) && $min > $max) {
            $violations[] = new TypeViolation('config.min', 'min cannot exceed max.');
        }

        return $violations;
    }

    public function validateValue(mixed $value, array $config, CustomFieldDefinition $definition): array
    {
        $multi = $this->isMulti($config);
        $shape = $this->ensureMultiShape($value, $multi);
        if (null !== $shape) {
            return [$shape];
        }

        $violations = [];
        foreach ($this->walkValues($value, $multi) as [$path, $element]) {
            foreach ($this->validateScalar($element, $config) as $v) {
                $violations[] = new TypeViolation($path . $v->path, $v->message, $v->parameters);
            }
        }
        return $violations;
    }

    public function searchText(mixed $value, array $config): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($this->isMulti($config) && is_array($value)) {
            $parts = [];
            foreach ($value as $element) {
                if (is_int($element) || is_float($element)) {
                    $parts[] = (string) $element;
                }
            }
            return [] === $parts ? null : implode(' ', $parts);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<TypeViolation>
     */
    abstract protected function validateScalar(mixed $value, array $config): array;
}
