<?php

namespace App\Validator;

use App\Entity\Task;
use App\Service\ReminderScheduler;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidRemindersValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ReminderScheduler $scheduler,
    ) {
    }

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

        if (count($reminders) > ValidReminders::MAX_REMINDERS) {
            $this->violate($constraint->messageTooMany);
            return;
        }

        $keys = [];
        $needsDueDate = false;
        foreach ($reminders as $reminder) {
            if (!is_array($reminder) || !$this->scheduler->isValidShape($reminder)) {
                $this->violate($constraint->messageInvalidShape);
                return;
            }
            $key = $this->scheduler->canonicalKey($reminder);
            if (isset($keys[$key])) {
                $this->violate($constraint->messageDuplicate);
                return;
            }
            $keys[$key] = true;
            if ($this->scheduler->requiresDueDate($reminder)) {
                $needsDueDate = true;
            }
        }

        if ($needsDueDate && null === $value->getDueDate()) {
            $this->violate($constraint->messageRequiresDueDate);
        }
    }

    private function violate(string $message): void
    {
        $this->context->buildViolation($message)
            ->atPath('reminders')
            ->addViolation();
    }
}
