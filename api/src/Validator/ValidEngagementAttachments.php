<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Engagement. Asserts that every MediaObject in
 * `attachments`:
 *   - was uploaded by a member of the engagement's space (any direct or
 *     group-inherited member), AND
 *   - has kind=attachment (avatars are private to their owner's profile
 *     and shouldn't end up as an engagement attachment by accident).
 *
 * Mirrors {@see ValidSpaceAttachments}.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidEngagementAttachments extends Constraint
{
    public string $messageNotMember = 'You can only attach files uploaded by a space member.';
    public string $messageWrongKind = 'Only attachment-kind media can be attached to an engagement.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
