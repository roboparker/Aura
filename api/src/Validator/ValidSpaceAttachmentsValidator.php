<?php

namespace App\Validator;

use App\Entity\MediaObject;
use App\Entity\Space;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidSpaceAttachmentsValidator extends ConstraintValidator
{
    public function __construct(private Security $security)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidSpaceAttachments) {
            throw new UnexpectedTypeException($constraint, ValidSpaceAttachments::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Space) {
            throw new UnexpectedValueException($value, Space::class);
        }

        // Effective members already covers direct + group-inherited
        // membership. Include the current security user too so the
        // very first attachment upload during space creation doesn't
        // 422 before the creator's membership is persisted.
        $memberIds = [];
        foreach ($value->getEffectiveUsers() as $id => $member) {
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

        // The invoice logo (#669) plays by the same rules, but must be an
        // image (avatar-kind — publicly served, image-validated on upload)
        // and uploaded by a member.
        $logo = $value->getInvoiceLogo();
        if (null !== $logo) {
            $logoOwner = $logo->getOwner();
            if (
                null === $logoOwner || null === $logoOwner->getId()
                || !isset($memberIds[(string) $logoOwner->getId()])
            ) {
                $this->context->buildViolation($constraint->messageNotMember)
                    ->atPath('invoiceLogo')
                    ->addViolation();
                return;
            }
            if (MediaObject::KIND_AVATAR !== $logo->getKind()) {
                $this->context->buildViolation('The invoice logo must be an uploaded image.')
                    ->atPath('invoiceLogo')
                    ->addViolation();
            }
        }
    }
}
