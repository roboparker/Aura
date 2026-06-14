<?php

namespace App\Validator;

use App\Entity\Task;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidRecurrenceValidator extends ConstraintValidator
{
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

        $frequency = $rule['frequency'] ?? null;
        $interval = $rule['interval'] ?? null;
        $hasShape = is_string($frequency)
            && in_array($frequency, ValidRecurrence::ALLOWED_FREQUENCIES, true)
            && is_int($interval)
            && $interval >= 1;

        if (!$hasShape) {
            $this->context->buildViolation($constraint->messageInvalidShape)
                ->atPath('recurrenceRule')
                ->addViolation();
            return;
        }

        if (!$this->validateByDay($rule, $constraint)) {
            return;
        }

        if ('monthly' === $frequency && !$this->validateMonthly($rule, $constraint)) {
            return;
        }

        if (!$this->validateEnds($rule, $constraint)) {
            return;
        }

        if (null === $value->getDueDate()) {
            $this->context->buildViolation($constraint->messageRequiresDueDate)
                ->atPath('recurrenceRule')
                ->addViolation();
        }
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function validateByDay(array $rule, ValidRecurrence $constraint): bool
    {
        $byDay = $rule['byDay'] ?? null;
        if (null === $byDay) {
            return true;
        }
        if (!is_array($byDay)) {
            $this->addViolation($constraint->messageInvalidDays);
            return false;
        }
        foreach ($byDay as $token) {
            if (!is_string($token) || !in_array($token, ValidRecurrence::ALLOWED_DAYS, true)) {
                $this->addViolation($constraint->messageInvalidDays);
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function validateMonthly(array $rule, ValidRecurrence $constraint): bool
    {
        $mode = $rule['monthlyMode'] ?? 'day';
        if ('day' === $mode || null === $mode) {
            return true;
        }
        if ('weekday' !== $mode) {
            $this->addViolation($constraint->messageInvalidMonthly);
            return false;
        }
        $byDay = $rule['byDay'] ?? null;
        $bySetPos = $rule['bySetPos'] ?? null;
        $validDays = is_array($byDay) && 1 === count($byDay);
        $validPos = is_int($bySetPos) && (-1 === $bySetPos || ($bySetPos >= 1 && $bySetPos <= 5));
        if (!$validDays || !$validPos) {
            $this->addViolation($constraint->messageInvalidMonthly);
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function validateEnds(array $rule, ValidRecurrence $constraint): bool
    {
        $ends = $rule['ends'] ?? null;
        if (null === $ends) {
            return true;
        }
        if (!is_array($ends)) {
            $this->addViolation($constraint->messageInvalidEnds);
            return false;
        }
        $type = $ends['type'] ?? null;
        if ('count' === $type) {
            $count = $ends['count'] ?? null;
            if (!is_int($count) || $count < 1) {
                $this->addViolation($constraint->messageInvalidEnds);
                return false;
            }
            return true;
        }
        if ('until' === $type) {
            $until = $ends['until'] ?? null;
            if (!is_string($until) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
                $this->addViolation($constraint->messageInvalidEnds);
                return false;
            }
            return true;
        }
        $this->addViolation($constraint->messageInvalidEnds);
        return false;
    }

    private function addViolation(string $message): void
    {
        $this->context->buildViolation($message)
            ->atPath('recurrenceRule')
            ->addViolation();
    }
}
