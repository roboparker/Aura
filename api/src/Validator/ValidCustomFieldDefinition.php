<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on both custom-field definition sources — the
 * space-owned {@see App\Entity\CustomFieldDefinition} and the instance-wide
 * {@see App\Entity\GlobalCustomFieldDefinition} — that delegates per-kind
 * config validation to the matching strategy in the registry. Replaces the
 * hand-rolled `validateOptions` / `validateFooter` Callbacks the entity used
 * to host.
 *
 * The strategy owns its own config schema (`numeric.money` knows about
 * `currency`, `select.*` knows about `options`, etc.), so this
 * constraint just looks up the right strategy via `kind.subtype` and
 * forwards each `TypeViolation` it produces into the Symfony violation
 * context with the right relative path.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCustomFieldDefinition extends Constraint
{
    public string $messageUnknownType = 'Unknown custom-field type "{{ key }}".';
    public string $messageUnsupportedFooter = 'Footer kind "{{ kind }}" is not supported by {{ type }}.';
    public string $messageGlobalNoReference = 'Global custom fields cannot be reference fields.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
