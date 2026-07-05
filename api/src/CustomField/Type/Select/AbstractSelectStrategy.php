<?php

declare(strict_types=1);

namespace App\CustomField\Type\Select;

use App\CustomField\CustomFieldKind;
use App\CustomField\Footer\FooterKind;
use App\CustomField\Type\AbstractTypeStrategy;
use App\CustomField\Type\TypeViolation;
use App\Entity\CustomFieldDefinitionInterface;

/**
 * Shared validation + normalization for select kinds. The (single,
 * multi) subtypes diverge only in whether the value is one key or a
 * list of keys — every other rule (option-shape validation, key
 * membership, search boardion) is identical.
 *
 * Config: `{options: [{key, label, color?}], multi}`. We upgraded
 * the legacy flat `string[]` to a structured object list so labels
 * can be edited without rewriting every historical task value — the
 * stable identifier is `key`, the user-facing string is `label`,
 * and `color` is optional UI styling.
 *
 * Footer: `count` (filled rows) + `breakdown` (per-option counts) —
 * sum/avg/min/max aren't meaningful on categorical choices.
 */
abstract class AbstractSelectStrategy extends AbstractTypeStrategy
{
    public function kind(): string
    {
        return CustomFieldKind::SELECT->value;
    }

    /**
     * @return list<value-of<FooterKind>>
     */
    public function supportedAggregations(): array
    {
        return [FooterKind::COUNT->value, FooterKind::BREAKDOWN->value];
    }

    public function validateConfig(array $config): array
    {
        $violations = $this->validateMultiConfig($config);

        $options = $config['options'] ?? null;
        if (!is_array($options) || [] === $options) {
            $violations[] = new TypeViolation('config.options', 'options must be a non-empty list.');
            return $violations;
        }
        if (!array_is_list($options)) {
            $violations[] = new TypeViolation('config.options', 'options must be a list.');
            return $violations;
        }

        $seenKeys = [];
        foreach ($options as $i => $option) {
            $path = 'config.options[' . $i . ']';
            if (!is_array($option)) {
                $violations[] = new TypeViolation($path, 'option must be an object.');
                continue;
            }
            $key = $option['key'] ?? null;
            if (!is_string($key) || '' === trim($key)) {
                $violations[] = new TypeViolation($path . '.key', 'option key is required.');
            } elseif (isset($seenKeys[$key])) {
                $violations[] = new TypeViolation($path . '.key', sprintf('Duplicate option key "%s".', $key));
            } else {
                $seenKeys[$key] = true;
            }

            $label = $option['label'] ?? null;
            if (!is_string($label) || '' === trim($label)) {
                $violations[] = new TypeViolation($path . '.label', 'option label is required.');
            }

            $color = $option['color'] ?? null;
            if (null !== $color && (!is_string($color) || 1 !== preg_match('/^#?[0-9a-fA-F]{6}$/', $color))) {
                $violations[] = new TypeViolation($path . '.color', 'option color must be a 6-digit hex code.');
            }
        }

        return $violations;
    }

    public function validateValue(mixed $value, array $config, CustomFieldDefinitionInterface $definition): array
    {
        return $this->validateValueAgainstKeys($value, $this->keysFromConfig($config), $config);
    }

    public function searchText(mixed $value, array $config): ?string
    {
        $labels = $this->labelsFromConfig($config);
        $resolve = static function ($key) use ($labels): ?string {
            if (!is_string($key)) {
                return null;
            }
            return $labels[$key] ?? null;
        };

        if ($this->isMulti($config) && is_array($value)) {
            $parts = array_values(array_filter(array_map($resolve, $value), static fn ($v) => null !== $v));
            return [] === $parts ? null : implode(' ', $parts);
        }
        $label = $resolve($value);
        return null === $label ? null : $label;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    protected function keysFromConfig(array $config): array
    {
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }
        $keys = [];
        foreach ($options as $option) {
            if (is_array($option) && is_string($option['key'] ?? null)) {
                $keys[] = $option['key'];
            }
        }
        return $keys;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    protected function labelsFromConfig(array $config): array
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
     * @param list<string> $keys
     * @param array<string, mixed> $config
     * @return list<TypeViolation>
     */
    abstract protected function validateValueAgainstKeys(mixed $value, array $keys, array $config): array;
}
