<?php

namespace App\Validator;

use App\Entity\Task;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidAssigneesValidator extends ConstraintValidator
{
    public function __construct(private Security $security)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidAssignees) {
            throw new UnexpectedTypeException($constraint, ValidAssignees::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Task) {
            throw new UnexpectedValueException($value, Task::class);
        }

        $allowed = [];
        // On POST the owner is set by TaskOwnerProcessor *after* validation,
        // so it's null here — fall back to the current user, which is
        // exactly who the processor will assign moments later.
        $owner = $value->getOwner() ?? ($this->security->getUser() instanceof User ? $this->security->getUser() : null);
        if (null !== $owner && null !== $owner->getId()) {
            $allowed[(string) $owner->getId()] = true;
        }
        $project = $value->getProject();
        if (null !== $project) {
            foreach ($project->getEffectiveMembers() as $id => $_member) {
                $allowed[$id] = true;
            }
        }

        foreach ($value->getAssignees() as $assignee) {
            $id = $assignee->getId();
            if (null === $id || !isset($allowed[(string) $id])) {
                $this->context->buildViolation($constraint->messageNotAllowed)
                    ->atPath('assignees')
                    ->addViolation();
                return;
            }
        }
    }
}
