<?php

declare(strict_types=1);

namespace App\CustomField\Type;

use App\CustomField\Footer\FooterKind;

/**
 * Shared scaffolding for {@see CustomFieldTypeInterface} implementations.
 * Provides the boilerplate `key()` composition, sensible defaults for
 * `supportsMulti()` / `supportedAggregations()` / `normalize()` /
 * `searchText()`, and a `config.multi` shape check that strategies can
 * call into from their own `validateConfig()`.
 *
 * Concrete strategies override what they need; the defaults err on the
 * conservative side (no multi, count-only aggregation, identity
 * normalization, no search projection) so a strategy that forgets to
 * declare these doesn't accidentally claim capabilities it can't deliver.
 */
abstract class AbstractTypeStrategy implements CustomFieldTypeInterface
{
    public function key(): string
    {
        return $this->kind() . '.' . $this->subtype();
    }

    public function supportsMulti(): bool
    {
        return false;
    }

    /**
     * Every strategy supports `count` (number of rows that supplied a
     * non-null value). Kinds that can compute richer aggregates
     * (numeric: sum/avg/min/max, date: min/max) override.
     *
     * @return list<string>
     */
    public function supportedAggregations(): array
    {
        return [FooterKind::COUNT->value];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        return $value;
    }

    public function searchText(mixed $value, array $config): ?string
    {
        return null;
    }

    public function validateConfig(array $config): array
    {
        return $this->validateMultiConfig($config);
    }

    /**
     * Shape check for `config.multi`: must be a boolean when present,
     * and may only be `true` if the strategy claims `supportsMulti()`.
     * Non-multi strategies that get `multi: true` should fail loud
     * rather than silently accept a list-of-values shape.
     *
     * @param array<string, mixed> $config
     * @return list<TypeViolation>
     */
    protected function validateMultiConfig(array $config): array
    {
        $multi = $config['multi'] ?? null;
        if (null === $multi) {
            return [];
        }
        if (!is_bool($multi)) {
            return [new TypeViolation('config.multi', 'multi must be a boolean.')];
        }
        if ($multi && !$this->supportsMulti()) {
            return [new TypeViolation('config.multi', sprintf('multi is not supported for %s.', $this->key()))];
        }
        return [];
    }

    /**
     * Whether `config.multi` is engaged on this definition. Centralises
     * the "default false" choice so every strategy reads the same way.
     *
     * @param array<string, mixed> $config
     */
    protected function isMulti(array $config): bool
    {
        return $this->supportsMulti() && true === ($config['multi'] ?? false);
    }
}
