<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Space. Asserts that every MediaObject in
 * `attachments`:
 *   - was uploaded by a space member (any direct or group-inherited
 *     member can drop a file), AND
 *   - has kind=attachment (avatars are private to their owner's profile
 *     and shouldn't end up as a space attachment by accident).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidSpaceAttachments extends Constraint
{
    public string $messageNotMember = 'You can only attach files uploaded by a space member.';
    public string $messageWrongKind = 'Only attachment-kind media can be attached to a space.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
