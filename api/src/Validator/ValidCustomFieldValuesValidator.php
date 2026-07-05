<?php

namespace App\Validator;

use App\CustomField\CustomFieldTypeRegistry;
use App\Entity\CustomFieldDefinitionInterface;
use App\Entity\CustomFieldValue;
use App\Entity\Board;
use App\Entity\Task;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Polices the embedded `customFieldValues` collection on a Task. The
 * big per-type `match` block this used to run is gone — the registry
 * looks up the strategy for each (kind, subtype) pair and the
 * strategy owns the rules. Adding a new kind is a one-class change
 * (drop a strategy implementing {@see App\CustomField\Type\CustomFieldTypeInterface}).
 *
 * Top-level invariants still live here because they cross multiple
 * CFVs / the parent task: board-scope, duplicate-definition
 * detection, and required-field enforcement.
 */
final class ValidCustomFieldValuesValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CustomFieldTypeRegistry $registry,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCustomFieldValues) {
            throw new UnexpectedTypeException($constraint, ValidCustomFieldValues::class);
        }
        if (null === $value) {
            return;
        }
        if (!$value instanceof Task) {
            throw new UnexpectedValueException($value, Task::class);
        }

        $values = $value->getCustomFieldValues();
        $board = $value->getBoard();

        // A task without a board can't have custom values — every
        // definition is board-scoped. Only flag this when the client
        // actually sent values; bare boardless tasks are fine.
        if (count($values) > 0 && null === $board) {
            $this->context->buildViolation($constraint->messageNoProject)
                ->atPath('customFieldValues')
                ->addViolation();
            return;
        }

        $seenDefinitionIds = [];
        $providedDefinitionIds = [];

        foreach ($values as $index => $cfv) {
            $spaceDefinition = $cfv->getDefinition();
            $globalDefinition = $cfv->getGlobalDefinition();

            // XOR: a value points at exactly one definition source. Neither
            // or both is the transient invalid state the DB CHECK also guards.
            if ((null === $spaceDefinition) === (null === $globalDefinition)) {
                $this->context->buildViolation($constraint->messageDefinitionSource)
                    ->atPath(sprintf('customFieldValues[%d].definition', $index))
                    ->addViolation();
                continue;
            }

            $definition = $spaceDefinition ?? $globalDefinition;
            if (null === $definition) {
                continue;
            }

            // A value is only legal for a definition the task's board has
            // opted into — a space-owned field via the board.customFieldDefinitions
            // M2M, a global field via board.globalCustomFieldDefinitions.
            $attached = null !== $board && (
                null !== $spaceDefinition
                    ? $this->boardHasDefinition($board->getCustomFieldDefinitions(), $spaceDefinition)
                    : $this->boardHasDefinition($board->getGlobalCustomFieldDefinitions(), $definition)
            );
            if (!$attached) {
                $this->context->buildViolation($constraint->messageWrongProject)
                    ->setParameter('{{ name }}', $definition->getName())
                    ->atPath(sprintf('customFieldValues[%d].definition', $index))
                    ->addViolation();
                continue;
            }

            $defId = (string) $definition->getId();
            if (isset($seenDefinitionIds[$defId])) {
                $this->context->buildViolation($constraint->messageDuplicate)
                    ->setParameter('{{ name }}', $definition->getName())
                    ->atPath(sprintf('customFieldValues[%d].definition', $index))
                    ->addViolation();
                continue;
            }
            $seenDefinitionIds[$defId] = true;
            $providedDefinitionIds[$defId] = $cfv;

            $this->dispatchToStrategy($cfv, $definition, $constraint, $index);
        }

        if (null !== $board && null !== $board->getId()) {
            $this->enforceRequired($board, $providedDefinitionIds, $constraint);
        }
    }

    private function dispatchToStrategy(
        CustomFieldValue $cfv,
        CustomFieldDefinitionInterface $definition,
        ValidCustomFieldValues $constraint,
        int $index,
    ): void {
        $raw = $cfv->getValue();
        if (null === $raw) {
            // Required-ness handled separately; null is fine here.
            return;
        }

        $key = $definition->getTypeKey();
        if (!$this->registry->has($key)) {
            $this->context->buildViolation($constraint->messageUnknownType)
                ->setParameter('{{ name }}', $definition->getName())
                ->setParameter('{{ key }}', $key)
                ->atPath(sprintf('customFieldValues[%d].value', $index))
                ->addViolation();
            return;
        }

        $strategy = $this->registry->get($key);
        foreach ($strategy->validateValue($raw, $definition->getConfig(), $definition) as $violation) {
            $path = sprintf('customFieldValues[%d].value', $index);
            if ('' !== $violation->path) {
                // TypeViolation paths are relative — element index
                // (`[i]`) or sub-property (`.amount`). Concatenate
                // directly: the leading bracket / dot is part of the
                // strategy's emitted path.
                $path .= $violation->path;
            }
            $this->context->buildViolation($violation->message)
                ->setParameters($violation->parameters)
                ->atPath($path)
                ->addViolation();
        }
    }

    /**
     * @param iterable<CustomFieldDefinitionInterface> $collection
     */
    private function boardHasDefinition(iterable $collection, CustomFieldDefinitionInterface $definition): bool
    {
        $target = (string) $definition->getId();
        foreach ($collection as $candidate) {
            if ((string) $candidate->getId() === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, CustomFieldValue> $providedDefinitionIds
     */
    private function enforceRequired(
        Board $board,
        array $providedDefinitionIds,
        ValidCustomFieldValues $constraint,
    ): void {
        // Required = the board's opted-in fields (space + global) that
        // aren't nullable. Both sources share the value-set keyed by def id.
        $definitions = array_merge(
            $board->getCustomFieldDefinitions()->toArray(),
            $board->getGlobalCustomFieldDefinitions()->toArray(),
        );

        foreach ($definitions as $definition) {
            if ($definition->isNullable()) {
                continue;
            }
            $defId = (string) $definition->getId();
            $cfv = $providedDefinitionIds[$defId] ?? null;
            if (null === $cfv || $this->isEmpty($cfv->getValue())) {
                $this->context->buildViolation($constraint->messageRequired)
                    ->setParameter('{{ name }}', $definition->getName())
                    ->atPath('customFieldValues')
                    ->addViolation();
            }
        }
    }

    private function isEmpty(mixed $value): bool
    {
        if (null === $value) {
            return true;
        }
        if (is_string($value) && '' === trim($value)) {
            return true;
        }
        if (is_array($value) && [] === $value) {
            return true;
        }
        return false;
    }
}
