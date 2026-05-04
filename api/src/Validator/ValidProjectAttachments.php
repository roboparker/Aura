<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Project. Asserts that every MediaObject in
 * `attachments`:
 *   - was uploaded by a project member (so any member can drop a file —
 *     this is looser than ValidTaskAttachments which only allows the task
 *     owner's uploads), AND
 *   - has kind=attachment (avatars are private to their owner's profile —
 *     they shouldn't show up as a project attachment by accident).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidProjectAttachments extends Constraint
{
    public string $messageNotMember = 'You can only attach files uploaded by a project member.';
    public string $messageWrongKind = 'Only attachment-kind media can be attached to a project.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
