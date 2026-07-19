<?php

namespace App\Validator;

use App\Entity\Project;
use App\Entity\MediaObject;
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

        $space = $value->getSpace();
        $memberIds = [];
        if (null !== $space) {
            foreach ($space->getEffectiveUsers() as $id => $member) {
                $memberIds[$id] = true;
            }
        }
        // Include the current user so the very first attachment during create
        // doesn't 422 before the space membership graph is fully resolved.
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
