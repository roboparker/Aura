<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on {@see App\Entity\Task}. Polices the
 * per-task custom-field values payload:
 *   - every {@see App\Entity\CustomFieldValue} references a definition
 *     that belongs to the task's project (no cross-project IRIs),
 *   - no two values reference the same definition (uniqueness mirrors
 *     the DB constraint, raised as a clean 422 instead of leaking the
 *     constraint name),
 *   - the value's shape matches the definition's strategy
 *     (kind/subtype/config) — dispatched through
 *     {@see App\CustomField\CustomFieldTypeRegistry}, so the actual
 *     rules live in the strategy class for that type,
 *   - every definition flagged `nullable: false` is present with a
 *     non-empty value at save time.
 *
 * The strategy registry produces {@see App\CustomField\Type\TypeViolation}
 * instances with relative paths; the validator attaches them under
 * `customFieldValues[<i>].value(.<path>)?` so the API surface shows
 * exactly which CFV (and which subfield, for shapes like money or
 * multi-value lists) failed.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCustomFieldValues extends Constraint
{
    public string $messageNoProject = 'Custom fields require the task to belong to a project.';
    public string $messageDefinitionSource = 'A custom field value must reference exactly one definition (space or global).';
    public string $messageWrongProject = 'Custom field "{{ name }}" does not belong to this task\'s project.';
    public string $messageDuplicate = 'Custom field "{{ name }}" is set more than once.';
    public string $messageRequired = 'Custom field "{{ name }}" is required.';
    public string $messageUnknownType = 'Custom field "{{ name }}" has an unknown type "{{ key }}".';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
