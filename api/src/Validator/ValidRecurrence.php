<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Task. Two rules:
 *  1. `recurrenceRule` must be a `{frequency, interval}` shape, where
 *     frequency is daily/weekly/monthly/yearly and interval is a positive
 *     integer.
 *  2. A recurrence rule may only be set when a `dueDate` is also set —
 *     there's no anchor to advance off otherwise.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidRecurrence extends Constraint
{
    public string $messageRequiresDueDate = 'A recurrence rule requires a due date.';
    public string $messageInvalidShape = 'Recurrence rule must be {"frequency": daily|weekly|monthly|yearly, "interval": positive integer}.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
