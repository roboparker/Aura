<?php

namespace App\Validator;

use App\Entity\Task;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidRecurrenceValidator extends ConstraintValidator
{
    private const ALLOWED_FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRecurrence) {
            throw new UnexpectedTypeException($constraint, ValidRecurrence::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Task) {
            throw new UnexpectedValueException($value, Task::class);
        }

        $rule = $value->getRecurrenceRule();
        if (null === $rule) {
            return;
        }

        $hasShape = in_array($rule['frequency'], self::ALLOWED_FREQUENCIES, true)
            && $rule['interval'] >= 1;

        if (!$hasShape) {
            $this->context->buildViolation($constraint->messageInvalidShape)
                ->atPath('recurrenceRule')
                ->addViolation();
            return;
        }

        if (null === $value->getDueDate()) {
            $this->context->buildViolation($constraint->messageRequiresDueDate)
                ->atPath('recurrenceRule')
                ->addViolation();
        }
    }
}
