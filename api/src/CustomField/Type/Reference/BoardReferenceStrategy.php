<?php

declare(strict_types=1);

namespace App\CustomField\Type\Reference;

use App\Entity\Board;
use App\Entity\Space;

/**
 * `reference.board` — references one or more boards. Scope is the
 * field's space; a board is in-scope iff it lives in the same space.
 */
final class BoardReferenceStrategy extends AbstractReferenceStrategy
{
    public function subtype(): string
    {
        return 'board';
    }

    protected function targetClass(): string
    {
        return Board::class;
    }

    protected function iriPrefix(): string
    {
        return '/boards/';
    }

    protected function displayLabel(object $target): string
    {
        return $target instanceof Board ? $target->getTitle() : '';
    }

    protected function isInScope(object $target, Space $space): bool
    {
        if (!$target instanceof Board) {
            return false;
        }
        $targetSpace = $target->getSpace();
        if (null === $targetSpace) {
            return false;
        }
        return (string) $targetSpace->getId() === (string) $space->getId();
    }
}
