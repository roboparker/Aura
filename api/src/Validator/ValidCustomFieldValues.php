<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Task. Polices the per-task custom-field
 * values payload (#84):
 *   - every {@see App\Entity\CustomFieldValue} references a definition
 *     that belongs to the task's project (no cross-project IRIs),
 *   - no two values reference the same definition (uniqueness mirrors
 *     the DB constraint, but raised as a clean 422),
 *   - the scalar in `value` matches the definition's type,
 *   - dropdown values come from the definition's `options` list,
 *   - every definition flagged `required: true` is present with a
 *     non-empty value when the task is saved.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCustomFieldValues extends Constraint
{
    public string $messageNoProject = 'Custom fields require the task to belong to a project.';
    public string $messageWrongProject = 'Custom field "{{ name }}" does not belong to this task\'s project.';
    public string $messageDuplicate = 'Custom field "{{ name }}" is set more than once.';
    public string $messageWrongType = 'Custom field "{{ name }}" expects a {{ type }} value.';
    public string $messageNotInOptions = 'Custom field "{{ name }}" must be one of the configured options.';
    public string $messageRequired = 'Custom field "{{ name }}" is required.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
