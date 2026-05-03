<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Task. Asserts that every MediaObject in
 * `attachments`:
 *   - was uploaded by the current user (no attaching someone else's
 *     avatar by IRI guess), AND
 *   - has kind=attachment (avatars are private to their owner's profile
 *     — they shouldn't show up as a task attachment by accident).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidTaskAttachments extends Constraint
{
    public string $messageNotOwner = 'You can only attach files you uploaded yourself.';
    public string $messageWrongKind = 'Only attachment-kind media can be attached to a task.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
