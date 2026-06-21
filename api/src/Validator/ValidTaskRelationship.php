<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on TaskRelationship. Rejects a task relating to
 * itself, and a duplicate of an existing relationship of the same type between
 * the two tasks in either direction (so `A parent-of B` blocks both a repeat
 * and the reverse `B parent-of A`, while still allowing a different type
 * between the same pair).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidTaskRelationship extends Constraint
{
    public string $messageSelf = 'A task cannot relate to itself.';
    public string $messageDuplicate = 'These tasks already have this relationship.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
