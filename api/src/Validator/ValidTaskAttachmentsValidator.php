<?php

namespace App\Validator;

use App\Entity\MediaObject;
use App\Entity\Task;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidTaskAttachmentsValidator extends ConstraintValidator
{
    public function __construct(private Security $security)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidTaskAttachments) {
            throw new UnexpectedTypeException($constraint, ValidTaskAttachments::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Task) {
            throw new UnexpectedValueException($value, Task::class);
        }

        // Mirror ValidAssignees: on POST the owner is set after validation
        // by TaskOwnerProcessor, so the current security user stands in for
        // the eventual owner.
        $owner = $value->getOwner() ?? ($this->security->getUser() instanceof User ? $this->security->getUser() : null);
        if (null === $owner || null === $owner->getId()) {
            return;
        }

        foreach ($value->getAttachments() as $attachment) {
            $attachmentOwner = $attachment->getOwner();
            if (null === $attachmentOwner || null === $attachmentOwner->getId()
                || (string) $attachmentOwner->getId() !== (string) $owner->getId()
            ) {
                $this->context->buildViolation($constraint->messageNotOwner)
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
