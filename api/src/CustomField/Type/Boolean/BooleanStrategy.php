<?php

declare(strict_types=1);

namespace App\CustomField\Type\Boolean;

use App\CustomField\CustomFieldKind;
use App\CustomField\Type\AbstractTypeStrategy;
use App\CustomField\Type\TypeViolation;
use App\Entity\CustomFieldDefinition;

/**
 * `boolean.boolean` — single-value checkbox. The only subtype under
 * `boolean`; kept as a kind of its own (rather than folded into
 * select.single with two options) so existing pre-#227 checkbox data
 * round-trips without re-keying as `{true,false}` option keys.
 *
 * Config: `{}` (no knobs).
 * Multi: not supported — a checkbox list is `select.multi` of two
 * options, semantically distinct.
 * Footer: `count` (number of rows where the box is non-null).
 */
final class BooleanStrategy extends AbstractTypeStrategy
{
    public function kind(): string
    {
        return CustomFieldKind::BOOLEAN->value;
    }

    public function subtype(): string
    {
        return 'boolean';
    }

    public function validateValue(mixed $value, array $config, CustomFieldDefinition $definition): array
    {
        if (is_bool($value)) {
            return [];
        }
        return [new TypeViolation('', 'Expected a boolean.')];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        return is_bool($value) ? $value : $value;
    }
}
