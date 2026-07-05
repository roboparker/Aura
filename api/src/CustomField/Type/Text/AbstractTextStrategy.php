<?php

declare(strict_types=1);

namespace App\CustomField\Type\Text;

use App\CustomField\CustomFieldKind;
use App\CustomField\Type\AbstractTypeStrategy;
use App\CustomField\Type\MultiValueHelper;
use App\CustomField\Type\TypeViolation;
use App\Entity\CustomFieldDefinitionInterface;

/**
 * Shared validation + normalization for every text-flavoured kind
 * (plain text, rich markdown, URL). The three subtypes diverge only in
 * their per-scalar rule (`validateScalar()` / pattern enforcement) —
 * everything else (multi shape, min/max length, normalization, search
 * projection) is identical, so it lives here.
 *
 * Config shape: `{minLength?, maxLength?, pattern?, multi}`.
 *
 *   - `minLength` / `maxLength` are inclusive bounds on the trimmed
 *     character count (or per-element character count when multi).
 *   - `pattern` is a JS-compatible RegExp body (no delimiters, no
 *     flags) — we apply it via PHP's `preg_match` with `~...~u`
 *     wrapping so Unicode characters are handled the way clients
 *     expect.
 *   - `multi: true` switches the value shape to a list of strings.
 *
 * Footer: `count` only — sum/avg/min/max on freeform text aren't
 * meaningful and would surprise clients more than they'd help.
 */
abstract class AbstractTextStrategy extends AbstractTypeStrategy
{
    use MultiValueHelper;

    public function kind(): string
    {
        return CustomFieldKind::TEXT->value;
    }

    public function supportsMulti(): bool
    {
        return true;
    }

    public function validateConfig(array $config): array
    {
        $violations = $this->validateMultiConfig($config);

        $min = $config['minLength'] ?? null;
        if (null !== $min && (!is_int($min) || $min < 0)) {
            $violations[] = new TypeViolation('config.minLength', 'minLength must be a non-negative integer.');
        }

        $max = $config['maxLength'] ?? null;
        if (null !== $max && (!is_int($max) || $max < 1)) {
            $violations[] = new TypeViolation('config.maxLength', 'maxLength must be a positive integer.');
        }

        if (is_int($min) && is_int($max) && $min > $max) {
            $violations[] = new TypeViolation('config.minLength', 'minLength cannot exceed maxLength.');
        }

        $pattern = $config['pattern'] ?? null;
        if (null !== $pattern) {
            if (!is_string($pattern)) {
                $violations[] = new TypeViolation('config.pattern', 'pattern must be a string.');
            } elseif (false === @preg_match('~' . $pattern . '~u', '')) {
                // preg_match returns false when the regex itself is
                // malformed — catch that here so a bad pattern doesn't
                // leak into the value-validation hot path.
                $violations[] = new TypeViolation('config.pattern', 'pattern must be a valid regular expression.');
            }
        }

        return $violations;
    }

    public function validateValue(mixed $value, array $config, CustomFieldDefinitionInterface $definition): array
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

    public function normalize(mixed $value, array $config): mixed
    {
        if (null === $value) {
            return null;
        }
        if ($this->isMulti($config)) {
            if (!is_array($value)) {
                return $value;
            }
            return array_map([$this, 'normalizeScalar'], $value);
        }
        return $this->normalizeScalar($value);
    }

    public function searchText(mixed $value, array $config): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($this->isMulti($config) && is_array($value)) {
            $parts = array_filter(
                array_map(static fn ($v) => is_string($v) ? trim($v) : null, $value),
                static fn (?string $v) => null !== $v && '' !== $v,
            );
            return [] === $parts ? null : implode(' ', $parts);
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return '' === $trimmed ? null : $trimmed;
        }
        return null;
    }

    /**
     * Per-scalar rule check (called for each element under multi, or
     * once for the bare value under single). Strategies override to
     * layer subtype-specific constraints (URL parse, markdown shape, …)
     * on top of the shared length + pattern enforcement here.
     *
     * @param array<string, mixed> $config
     * @return list<TypeViolation>
     */
    protected function validateScalar(mixed $value, array $config): array
    {
        if (!is_string($value)) {
            return [new TypeViolation('', 'Expected a string.')];
        }

        $violations = [];
        $length = mb_strlen($value);

        $min = $config['minLength'] ?? null;
        if (is_int($min) && $length < $min) {
            $violations[] = new TypeViolation('', sprintf('Value must be at least %d characters long.', $min));
        }

        $max = $config['maxLength'] ?? null;
        if (is_int($max) && $length > $max) {
            $violations[] = new TypeViolation('', sprintf('Value cannot be longer than %d characters.', $max));
        }

        $pattern = $config['pattern'] ?? null;
        if (is_string($pattern) && 1 !== @preg_match('~' . $pattern . '~u', $value)) {
            $violations[] = new TypeViolation('', 'Value does not match the required pattern.');
        }

        return $violations;
    }

    protected function normalizeScalar(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}
