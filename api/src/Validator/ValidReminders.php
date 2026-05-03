<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Task. Validates the `reminders` field:
 *  - Each entry must be one of {@see self::ALLOWED_OFFSETS}.
 *  - Duplicates are rejected.
 *  - When non-empty, a `dueDate` must also be set (a reminder offset has
 *    nothing to anchor to without a due date).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidReminders extends Constraint
{
    /**
     * Allowlist of reminder offset strings. Kept short and human-readable —
     * the dispatcher and the PWA both reference this list.
     */
    public const ALLOWED_OFFSETS = ['15m', '1h', '1d'];

    public string $messageRequiresDueDate = 'Reminders require a due date.';
    public string $messageInvalidOffset = 'Reminder offsets must be one of: 15m, 1h, 1d.';
    public string $messageDuplicate = 'Reminder offsets must not contain duplicates.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
