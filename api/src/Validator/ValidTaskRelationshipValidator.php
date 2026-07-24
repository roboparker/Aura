<?php

namespace App\Validator;

use App\Entity\Task;
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

            return;
        }

        // Subtask invariants apply only to `parent` links (source = parent,
        // target = child).
        if (TaskRelationship::TYPE_PARENT !== $value->getType()) {
            return;
        }

        // A subtask has exactly one parent. Adding a second would make "the
        // parent" ambiguous everywhere it's read.
        $currentParent = $this->repository->findParentLinkOf($target);
        if (null !== $currentParent && $currentParent !== $value) {
            $this->context->buildViolation($constraint->messageAlreadyHasParent)
                ->atPath('target')
                ->addViolation();

            return;
        }

        // No cycles: the proposed parent must not already sit somewhere below
        // the proposed child. Without this, a two-hop A→B→A loop slips past the
        // reverse-duplicate check (which only looks at the direct pair).
        if ($this->isDescendant($source, $target)) {
            $this->context->buildViolation($constraint->messageCycle)
                ->atPath('target')
                ->addViolation();
        }
    }

    /**
     * Whether `$ancestor` appears at or below `$candidate` in the subtask tree
     * — i.e. making `$ancestor` the parent of `$candidate` would close a loop.
     * Walks child links down from `$candidate`; the single-parent invariant
     * keeps the structure a tree, so the visited-set guards only against
     * pre-existing bad data rather than legitimate diamonds.
     */
    private function isDescendant(Task $ancestor, Task $candidate): bool
    {
        $queue = [$candidate];
        $seen = [];
        while ([] !== $queue) {
            $current = array_shift($queue);
            $key = (string) $current->getId();
            if ('' !== $key && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($current === $ancestor || true === $current->getId()?->equals($ancestor->getId())) {
                return true;
            }

            foreach ($this->repository->findChildLinksOf($current) as $childLink) {
                $child = $childLink->getTarget();
                if (null !== $child) {
                    $queue[] = $child;
                }
            }
        }

        return false;
    }
}
