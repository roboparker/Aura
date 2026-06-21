<?php

namespace App\Validator;

use App\Entity\TaskRelationship;
use App\Repository\TaskRelationshipRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidTaskRelationshipValidator extends ConstraintValidator
{
    public function __construct(
        private readonly TaskRelationshipRepository $repository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidTaskRelationship) {
            throw new UnexpectedTypeException($constraint, ValidTaskRelationship::class);
        }
        if (null === $value) {
            return;
        }
        if (!$value instanceof TaskRelationship) {
            throw new UnexpectedValueException($value, TaskRelationship::class);
        }

        $source = $value->getSource();
        $target = $value->getTarget();
        // NotNull on the columns reports the missing side; nothing to cross-check.
        if (null === $source || null === $target) {
            return;
        }

        if ($source === $target || true === $source->getId()?->equals($target->getId())) {
            $this->context->buildViolation($constraint->messageSelf)
                ->atPath('target')
                ->addViolation();

            return;
        }

        $existing = $this->repository->findBetween($source, $target, $value->getType());
        if (null !== $existing && $existing !== $value) {
            $this->context->buildViolation($constraint->messageDuplicate)
                ->atPath('target')
                ->addViolation();
        }
    }
}
