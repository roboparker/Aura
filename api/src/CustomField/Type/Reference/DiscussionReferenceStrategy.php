<?php

declare(strict_types=1);

namespace App\CustomField\Type\Reference;

use App\Entity\Discussion;
use App\Entity\Space;

/**
 * `reference.discussion` — references one or more discussions. Scope
 * is the field's space; a discussion is in-scope iff
 * `discussion.space === field.space`. The denormalised `space` column
 * is kept in sync with the parent board on persist + move, so this
 * comparison is cheap and consistent.
 */
final class DiscussionReferenceStrategy extends AbstractReferenceStrategy
{
    public function subtype(): string
    {
        return 'discussion';
    }

    protected function targetClass(): string
    {
        return Discussion::class;
    }

    protected function iriPrefix(): string
    {
        return '/discussions/';
    }

    protected function displayLabel(object $target): string
    {
        return $target instanceof Discussion ? $target->getTitle() : '';
    }

    protected function isInScope(object $target, Space $space): bool
    {
        if (!$target instanceof Discussion) {
            return false;
        }
        $targetSpace = $target->getSpace();
        if (null === $targetSpace) {
            return false;
        }
        return (string) $targetSpace->getId() === (string) $space->getId();
    }
}
