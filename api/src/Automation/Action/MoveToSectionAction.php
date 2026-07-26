<?php

declare(strict_types=1);

namespace App\Automation\Action;

use App\Automation\ActionDefinitionInterface;
use App\Automation\AutomationContext;
use App\Entity\TaskSection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves the task into a column.
 *
 * **Confined to the task's own board.** A section id belongs to exactly one
 * board, so a rule naming a foreign one would move a task onto a board it isn't
 * part of — the FK would allow it and nothing else would notice. Checked here
 * and failed closed.
 */
final class MoveToSectionAction implements ActionDefinitionInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'task.move_section';
    }

    public function label(): string
    {
        return 'Move to section';
    }

    public function description(): string
    {
        return 'Moves the task into a column on this board.';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'section', 'type' => 'section', 'label' => 'Move to'],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $section = $config['section'] ?? null;

        return is_string($section) && '' !== $section ? null : 'Choose a section.';
    }

    public function execute(AutomationContext $context, array $config): string
    {
        $sectionId = $config['section'] ?? null;
        if (!is_string($sectionId) || '' === $sectionId) {
            throw new \RuntimeException('No section configured.');
        }

        $section = $this->em->getRepository(TaskSection::class)->find($sectionId);
        if (!$section instanceof TaskSection) {
            throw new \RuntimeException('That section no longer exists.');
        }

        $taskBoard = $context->task->getBoard()?->getId();
        $sectionBoard = $section->getBoard()?->getId();
        if (null === $taskBoard || null === $sectionBoard || !$taskBoard->equals($sectionBoard)) {
            throw new \RuntimeException('That section belongs to a different board.');
        }

        if ($context->task->getSection() === $section) {
            return sprintf('already in "%s"', $section->getTitle());
        }

        $context->task->setSection($section);

        return sprintf('moved to "%s"', $section->getTitle());
    }
}
