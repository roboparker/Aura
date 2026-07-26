<?php

declare(strict_types=1);

namespace App\Automation\Trigger;

use App\Automation\AutomationContext;
use App\Automation\AutomationEvents;
use App\Automation\TriggerDefinitionInterface;
use App\Entity\TaskSection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires when a task lands in a particular section — the "moved to Done" rule
 * everyone writes first.
 *
 * Config is optional: with no section chosen it fires on any move, which is
 * useful hung in front of a condition.
 */
final class TaskMovedToSectionTrigger implements TriggerDefinitionInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'task.moved_to_section';
    }

    public function label(): string
    {
        return 'Task moves to a section';
    }

    public function description(): string
    {
        return 'Runs when a task is moved into a column — optionally, a specific one.';
    }

    public function event(): string
    {
        return AutomationEvents::TASK_UPDATED;
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'section',
                'type' => 'section',
                'label' => 'Section',
                'placeholder' => 'Any section',
            ],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $section = $config['section'] ?? null;
        if (null === $section || '' === $section) {
            return null;
        }

        return is_string($section) ? null : 'Pick a section.';
    }

    public function matches(AutomationContext $context, array $config): bool
    {
        // The section has to have actually changed — otherwise every edit to a
        // task already sitting in "Done" would re-fire the rule.
        if (!$context->changed('section')) {
            return false;
        }

        $wanted = $config['section'] ?? null;
        if (!is_string($wanted) || '' === $wanted) {
            return true;
        }

        $now = $context->valueAfter('section');
        if (!is_string($now) || $now !== $wanted) {
            return false;
        }

        // A section deleted since the rule was written can't match. Reading as
        // "no" beats throwing on every task edit on the board.
        return null !== $this->em->getRepository(TaskSection::class)->find($wanted);
    }
}
