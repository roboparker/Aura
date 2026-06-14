<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Task. Validates the (expanded) `reminders`
 * field — a list of relative/absolute reminder objects:
 *
 *  - relative: {type:"relative", value:int>=0, unit:"minutes"|"hours"|"days", repeat:bool}
 *  - absolute: {type:"absolute", at:ISO-8601, repeat:bool}
 *
 * Rules:
 *  - Each entry must match one of the shapes above.
 *  - Duplicates (same canonical key) are rejected.
 *  - At most {@see self::MAX_REMINDERS} entries.
 *  - A *relative* reminder needs a `dueDate` to anchor to; absolute
 *    reminders stand on their own clock and don't.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidReminders extends Constraint
{
    public const ALLOWED_UNITS = ['minutes', 'hours', 'days'];
    public const MAX_REMINDERS = 10;

    public string $messageRequiresDueDate = 'Relative reminders require a due date.';
    public string $messageInvalidShape = 'Each reminder must be a relative ({value, unit}) or absolute ({at}) entry.';
    public string $messageDuplicate = 'Reminders must not contain duplicates.';
    public string $messageTooMany = 'A task can have at most 10 reminders.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
