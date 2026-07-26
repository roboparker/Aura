<?php

declare(strict_types=1);

namespace App\Automation\Condition;

use App\Automation\AutomationContext;
use App\Automation\ConditionDefinitionInterface;
use App\Entity\Tag;

/**
 * Passes when the task carries a given tag.
 *
 * Matches on tag *name* rather than id. Tags are per-owner in this codebase, so
 * two people can hold different Tag rows both called "urgent" — an id would
 * make the rule silently stop matching depending on who edited the task.
 */
final class HasTagCondition implements ConditionDefinitionInterface
{
    public function type(): string
    {
        return 'task.has_tag';
    }

    public function label(): string
    {
        return 'Task has tag';
    }

    public function description(): string
    {
        return 'Continues only if the task is tagged with a given label.';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'tag', 'type' => 'text', 'label' => 'Tag name', 'placeholder' => 'urgent'],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $tag = $config['tag'] ?? null;

        return is_string($tag) && '' !== trim($tag) ? null : 'Enter a tag name.';
    }

    public function passes(AutomationContext $context, array $config): bool
    {
        $wanted = $config['tag'] ?? null;
        if (!is_string($wanted) || '' === trim($wanted)) {
            return false;
        }
        $wanted = mb_strtolower(trim($wanted));

        foreach ($context->task->getTags() as $tag) {
            if (mb_strtolower($tag->getTitle()) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
