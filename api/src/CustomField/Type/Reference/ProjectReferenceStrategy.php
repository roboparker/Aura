<?php

declare(strict_types=1);

namespace App\CustomField\Type\Reference;

use App\Entity\Project;
use App\Entity\Space;

/**
 * `reference.project` — references one or more projects. Scope is the
 * field's space; a project is in-scope iff it lives in the same space.
 */
final class ProjectReferenceStrategy extends AbstractReferenceStrategy
{
    public function subtype(): string
    {
        return 'project';
    }

    protected function targetClass(): string
    {
        return Project::class;
    }

    protected function iriPrefix(): string
    {
        return '/projects/';
    }

    protected function displayLabel(object $target): string
    {
        return $target instanceof Project ? $target->getTitle() : '';
    }

    protected function isInScope(object $target, Space $space): bool
    {
        if (!$target instanceof Project) {
            return false;
        }
        $targetSpace = $target->getSpace();
        if (null === $targetSpace) {
            return false;
        }
        return (string) $targetSpace->getId() === (string) $space->getId();
    }
}
