<?php

namespace App\Validator;

use App\Entity\MediaObject;
use App\Entity\Project;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidProjectAttachmentsValidator extends ConstraintValidator
{
    public function __construct(private Security $security)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidProjectAttachments) {
            throw new UnexpectedTypeException($constraint, ValidProjectAttachments::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Project) {
            throw new UnexpectedValueException($value, Project::class);
        }

        // Build the member-id set from the project's space (#185). On
        // POST the owner is added to the space inside
        // ProjectOwnerProcessor *after* validation, so include the
        // current security user too — without that, the very first
        // attachment upload during project creation would 422 every
        // time.
        $memberIds = [];
        foreach ($value->getEffectiveMembers() as $id => $member) {
            $memberIds[$id] = true;
        }
        $current = $this->security->getUser();
        if ($current instanceof User && null !== $current->getId()) {
            $memberIds[(string) $current->getId()] = true;
        }

        foreach ($value->getAttachments() as $attachment) {
            $owner = $attachment->getOwner();
            if (null === $owner || null === $owner->getId() || !isset($memberIds[(string) $owner->getId()])) {
                $this->context->buildViolation($constraint->messageNotMember)
                    ->atPath('attachments')
                    ->addViolation();
                return;
            }
            if ($attachment->getKind() !== MediaObject::KIND_ATTACHMENT) {
                $this->context->buildViolation($constraint->messageWrongKind)
                    ->atPath('attachments')
                    ->addViolation();
                return;
            }
        }
    }
}
