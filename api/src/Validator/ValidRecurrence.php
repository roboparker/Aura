<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Task. Validates the (expanded) `recurrenceRule`
 * shape and the cross-field rule that a recurrence requires a due date.
 *
 * Shape (see {@see \App\Service\RecurrenceCalculator}):
 *  - frequency: daily|weekly|monthly|yearly
 *  - interval:  positive integer
 *  - byDay:     optional array of MO..SU tokens (weekly + monthly-weekday)
 *  - monthlyMode: optional "day"|"weekday" (monthly only)
 *  - bySetPos:  optional int in 1..5 or -1 (monthly-weekday only)
 *  - ends:      optional {type: until, until: Y-m-d} | {type: count, count: >=1}
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidRecurrence extends Constraint
{
    public const ALLOWED_FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];
    public const ALLOWED_DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    public string $messageRequiresDueDate = 'A recurrence rule requires a due date.';
    public string $messageInvalidShape = 'Recurrence rule must have a valid frequency (daily|weekly|monthly|yearly) and a positive interval.';
    public string $messageInvalidDays = 'Recurrence byDay entries must be MO, TU, WE, TH, FR, SA or SU.';
    public string $messageInvalidMonthly = 'Monthly "weekday" mode requires a single byDay and a bySetPos of 1–5 or -1.';
    public string $messageInvalidEnds = 'Recurrence end condition is invalid.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
