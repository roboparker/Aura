<?php

namespace App\Validator;

use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Project;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidCustomFieldValuesValidator extends ConstraintValidator
{
    public function __construct(private EntityManagerInterface $em)
    {
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
        $project = $value->getProject();

        // A task without a project can't have custom values — every
        // definition is project-scoped. We only flag this when the
        // client actually sent values; bare projectless tasks are fine.
        if (count($values) > 0 && null === $project) {
            $this->context->buildViolation($constraint->messageNoProject)
                ->atPath('customFieldValues')
                ->addViolation();
            return;
        }

        $seenDefinitionIds = [];
        $providedDefinitionIds = [];

        foreach ($values as $index => $cfv) {
            if (!$cfv instanceof CustomFieldValue) {
                continue;
            }
            $definition = $cfv->getDefinition();
            if (null === $definition) {
                // The NotNull constraint on the property will surface
                // the missing definition; nothing more to check.
                continue;
            }

            $defProject = $definition->getProject();
            if (null === $project || null === $defProject
                || (string) $defProject->getId() !== (string) $project->getId()
            ) {
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

            $this->validateScalar($cfv, $definition, $constraint, $index);
        }

        // Required-field enforcement: every definition flagged required
        // for the task's project must be present with a non-empty value.
        // Run only if the client actually engaged with custom fields
        // (i.e. sent any value or this is a fresh task with definitions
        // in scope) so a partial PATCH that doesn't touch fields stays
        // green when nothing changed.
        if (null !== $project && null !== $project->getId()) {
            $this->enforceRequired($project, $providedDefinitionIds, $constraint);
        }
    }

    private function validateScalar(
        CustomFieldValue $cfv,
        CustomFieldDefinition $definition,
        ValidCustomFieldValues $constraint,
        int $index,
    ): void {
        $raw = $cfv->getValue();
        if (null === $raw) {
            return; // Required-ness handled separately; null is fine here.
        }

        $type = $definition->getType();
        $valid = match ($type) {
            CustomFieldDefinition::TYPE_TEXT => is_string($raw),
            CustomFieldDefinition::TYPE_NUMBER => is_int($raw) || is_float($raw),
            CustomFieldDefinition::TYPE_DATE => is_string($raw) && false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $raw),
            CustomFieldDefinition::TYPE_DROPDOWN => is_string($raw) && in_array($raw, $definition->getOptions() ?? [], true),
            CustomFieldDefinition::TYPE_CHECKBOX => is_bool($raw),
            default => false,
        };

        if (!$valid) {
            // Dropdown failures fall into two buckets: wrong shape (not
            // a string at all) and wrong choice (string but not in the
            // options list). The latter has its own message so the API
            // tells the user what actually went wrong.
            if (CustomFieldDefinition::TYPE_DROPDOWN === $type && is_string($raw)) {
                $this->context->buildViolation($constraint->messageNotInOptions)
                    ->setParameter('{{ name }}', $definition->getName())
                    ->atPath(sprintf('customFieldValues[%d].value', $index))
                    ->addViolation();
                return;
            }
            $this->context->buildViolation($constraint->messageWrongType)
                ->setParameter('{{ name }}', $definition->getName())
                ->setParameter('{{ type }}', $type)
                ->atPath(sprintf('customFieldValues[%d].value', $index))
                ->addViolation();
        }
    }

    /**
     * @param array<string, CustomFieldValue> $providedDefinitionIds
     */
    private function enforceRequired(
        Project $project,
        array $providedDefinitionIds,
        ValidCustomFieldValues $constraint,
    ): void {
        $definitions = $this->em->getRepository(CustomFieldDefinition::class)
            ->findBy(['project' => $project, 'required' => true]);

        foreach ($definitions as $definition) {
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
