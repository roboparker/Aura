<?php

declare(strict_types=1);

namespace App\CustomField\Type\Reference;

use App\Entity\Space;
use App\Entity\Task;

/**
 * `reference.task` — references one or more tasks. Scope is the
 * field's space; a task is in-scope iff its parent board lives in
 * the same space. Standalone (boardless) tasks are not referenceable
 * — they have no space, so the equality check always fails.
 */
final class TaskReferenceStrategy extends AbstractReferenceStrategy
{
    public function subtype(): string
    {
        return 'task';
    }

    protected function targetClass(): string
    {
        return Task::class;
    }

    protected function iriPrefix(): string
    {
        return '/tasks/';
    }

    protected function displayLabel(object $target): string
    {
        return $target instanceof Task ? $target->getTitle() : '';
    }

    protected function isInScope(object $target, Space $space): bool
    {
        if (!$target instanceof Task) {
            return false;
        }
        $board = $target->getBoard();
        if (null === $board) {
            return false;
        }
        $targetSpace = $board->getSpace();
        if (null === $targetSpace) {
            return false;
        }
        return (string) $targetSpace->getId() === (string) $space->getId();
    }
}
