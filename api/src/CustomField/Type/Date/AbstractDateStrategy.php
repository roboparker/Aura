<?php

declare(strict_types=1);

namespace App\CustomField\Type\Date;

use App\CustomField\CustomFieldKind;
use App\CustomField\Footer\FooterKind;
use App\CustomField\Type\AbstractTypeStrategy;
use App\CustomField\Type\MultiValueHelper;
use App\CustomField\Type\TypeViolation;
use App\Entity\CustomFieldDefinition;

/**
 * Shared validation + normalization for date-flavoured kinds. Each
 * subtype declares its expected wire format via {@see format()}; this
 * class drives the multi walk, the min/max bound check, and the FTS
 * search-text projection on top of that.
 *
 * Config: `{min?, max?, multi}`.
 *   - `min` / `max` are inclusive bounds in the subtype's wire format
 *     (so `Y-m-d` for date, `H:i` for time, ISO-8601 for datetime).
 *     Either or both may be set.
 *   - `multi: true` switches the value shape to a list of date strings.
 *
 * Footer: `min`, `max`, `count` — sum/avg aren't well-defined over dates.
 */
abstract class AbstractDateStrategy extends AbstractTypeStrategy
{
    use MultiValueHelper;

    public function kind(): string
    {
        return CustomFieldKind::DATE->value;
    }

    public function supportsMulti(): bool
    {
        return true;
    }

    /**
     * @return list<value-of<\App\CustomField\Footer\FooterKind>>
     */
    public function supportedAggregations(): array
    {
        return [
            FooterKind::MIN->value,
            FooterKind::MAX->value,
            FooterKind::COUNT->value,
        ];
    }

    /**
     * Strict format mask passed to {@see \DateTimeImmutable::createFromFormat}
     * with a leading `!` so unspecified components reset to zero
     * rather than picking up "now". Subtypes return their canonical
     * wire format.
     */
    abstract protected function format(): string;

    public function validateConfig(array $config): array
    {
        $violations = $this->validateMultiConfig($config);

        foreach (['min', 'max'] as $key) {
            $bound = $config[$key] ?? null;
            if (null === $bound) {
                continue;
            }
            if (!is_string($bound) || null === $this->parse($bound)) {
                $violations[] = new TypeViolation(
                    'config.' . $key,
                    sprintf('%s must be a valid %s.', $key, $this->subtype()),
                );
            }
        }

        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;
        if (is_string($min) && is_string($max)) {
            $minParsed = $this->parse($min);
            $maxParsed = $this->parse($max);
            if (null !== $minParsed && null !== $maxParsed && $minParsed > $maxParsed) {
                $violations[] = new TypeViolation('config.min', 'min cannot exceed max.');
            }
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
                if (is_string($element) && null !== $this->parse($element)) {
                    $parts[] = $element;
                }
            }
            return [] === $parts ? null : implode(' ', $parts);
        }
        return is_string($value) && null !== $this->parse($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<TypeViolation>
     */
    protected function validateScalar(mixed $value, array $config): array
    {
        if (!is_string($value)) {
            return [new TypeViolation('', sprintf('Expected a %s string.', $this->subtype()))];
        }
        $parsed = $this->parse($value);
        if (null === $parsed) {
            return [new TypeViolation('', sprintf('Value is not a valid %s.', $this->subtype()))];
        }

        $violations = [];
        $min = $config['min'] ?? null;
        if (is_string($min)) {
            $minParsed = $this->parse($min);
            if (null !== $minParsed && $parsed < $minParsed) {
                $violations[] = new TypeViolation('', sprintf('Value cannot be earlier than %s.', $min));
            }
        }
        $max = $config['max'] ?? null;
        if (is_string($max)) {
            $maxParsed = $this->parse($max);
            if (null !== $maxParsed && $parsed > $maxParsed) {
                $violations[] = new TypeViolation('', sprintf('Value cannot be later than %s.', $max));
            }
        }
        return $violations;
    }

    private function parse(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!' . $this->format(), $value);
        if (false === $parsed) {
            return null;
        }
        // PHP's createFromFormat is forgiving: '25:00' parses to the next
        // day with a warning rather than a failure. Round-trip the
        // formatted value and reject if it doesn't match — that's the
        // only way to catch overflows like '2026-02-30' or '25:00'.
        if ($parsed->format($this->format()) !== $value) {
            return null;
        }
        return $parsed;
    }
}
