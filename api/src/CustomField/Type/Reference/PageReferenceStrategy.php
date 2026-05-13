<?php

declare(strict_types=1);

namespace App\CustomField\Type\Reference;

use App\Entity\Page;
use App\Entity\Space;

/**
 * `reference.page` — references one or more pages. Scope is the
 * field's space; a page is in-scope iff `page.space === field.space`.
 */
final class PageReferenceStrategy extends AbstractReferenceStrategy
{
    public function subtype(): string
    {
        return 'page';
    }

    protected function targetClass(): string
    {
        return Page::class;
    }

    protected function iriPrefix(): string
    {
        return '/pages/';
    }

    protected function displayLabel(object $target): string
    {
        return $target instanceof Page ? $target->getTitle() : '';
    }

    protected function isInScope(object $target, Space $space): bool
    {
        if (!$target instanceof Page) {
            return false;
        }
        $targetSpace = $target->getSpace();
        if (null === $targetSpace) {
            return false;
        }
        return (string) $targetSpace->getId() === (string) $space->getId();
    }
}
