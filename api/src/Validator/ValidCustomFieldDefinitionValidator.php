<?php

declare(strict_types=1);

namespace App\Validator;

use App\CustomField\CustomFieldKind;
use App\CustomField\CustomFieldTypeRegistry;
use App\Entity\CustomFieldDefinitionInterface;
use App\Entity\GlobalCustomFieldDefinition;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Config + footer well-formedness for a custom-field definition. Runs for
 * BOTH definition sources — the space-owned {@see App\Entity\CustomFieldDefinition}
 * and the instance-wide {@see GlobalCustomFieldDefinition} — since both
 * implement {@see CustomFieldDefinitionInterface} and dispatch through the
 * same strategy registry.
 */
final class ValidCustomFieldDefinitionValidator extends ConstraintValidator
{
    public function __construct(private readonly CustomFieldTypeRegistry $registry)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCustomFieldDefinition) {
            throw new UnexpectedTypeException($constraint, ValidCustomFieldDefinition::class);
        }
        if (null === $value) {
            return;
        }
        if (!$value instanceof CustomFieldDefinitionInterface) {
            throw new UnexpectedValueException($value, CustomFieldDefinitionInterface::class);
        }

        // Global fields have no space, so a reference value has nothing to
        // scope its target IRIs against — disallow the whole `reference`
        // family for global definitions in v1.
        if ($value instanceof GlobalCustomFieldDefinition && CustomFieldKind::REFERENCE->value === $value->getKind()) {
            $this->context->buildViolation($constraint->messageGlobalNoReference)
                ->atPath('kind')
                ->addViolation();
            return;
        }

        $key = $value->getTypeKey();
        if (!$this->registry->has($key)) {
            $this->context->buildViolation($constraint->messageUnknownType)
                ->setParameter('{{ key }}', $key)
                ->atPath('subtype')
                ->addViolation();
            return;
        }

        $strategy = $this->registry->get($key);

        foreach ($strategy->validateConfig($value->getConfig()) as $violation) {
            $path = $violation->path;
            // Strategies emit paths rooted at `config.*`; the Symfony
            // form integration prefers dot-paths over bracket-paths.
            $this->context->buildViolation($violation->message)
                ->setParameters($violation->parameters)
                ->atPath('' === $path ? 'config' : $path)
                ->addViolation();
        }

        $footer = $value->getFooter();
        if (null !== $footer) {
            $footerKind = $footer['kind'];
            if (!in_array($footerKind, $strategy->supportedAggregations(), true)) {
                $this->context->buildViolation($constraint->messageUnsupportedFooter)
                    ->setParameter('{{ kind }}', $footerKind)
                    ->setParameter('{{ type }}', $key)
                    ->atPath('footer.kind')
                    ->addViolation();
            }
        }
    }
}
