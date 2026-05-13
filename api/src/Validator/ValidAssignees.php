<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Task. Asserts that every entry in `assignees` is
 * either the task's owner or (when the task belongs to a project) one of
 * that project's members. Assignment is a "who's responsible" label and
 * must not extend access to anyone who couldn't already see the task.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidAssignees extends Constraint
{
    public string $messageNotAllowed = 'Assignees must be the task owner or a member of its project.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
