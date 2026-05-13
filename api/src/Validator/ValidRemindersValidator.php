<?php

namespace App\Validator;

use App\Entity\Task;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidRemindersValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidReminders) {
            throw new UnexpectedTypeException($constraint, ValidReminders::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Task) {
            throw new UnexpectedValueException($value, Task::class);
        }

        $reminders = $value->getReminders();
        if (null === $reminders || [] === $reminders) {
            return;
        }

        if (count($reminders) !== count(array_unique($reminders))) {
            $this->context->buildViolation($constraint->messageDuplicate)
                ->atPath('reminders')
                ->addViolation();
            return;
        }

        foreach ($reminders as $offset) {
            if (!in_array($offset, ValidReminders::ALLOWED_OFFSETS, true)) {
                $this->context->buildViolation($constraint->messageInvalidOffset)
                    ->atPath('reminders')
                    ->addViolation();
                return;
            }
        }

        if (null === $value->getDueDate()) {
            $this->context->buildViolation($constraint->messageRequiresDueDate)
                ->atPath('reminders')
                ->addViolation();
        }
    }
}
