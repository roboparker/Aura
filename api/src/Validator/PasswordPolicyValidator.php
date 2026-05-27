<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Constraints\PasswordStrengthValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class PasswordPolicyValidator extends ConstraintValidator
{
    public function __construct(private int $minStrength)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PasswordPolicy) {
            throw new UnexpectedTypeException($constraint, PasswordPolicy::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        if (PasswordStrengthValidator::estimateStrength($value) < $this->minStrength) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
