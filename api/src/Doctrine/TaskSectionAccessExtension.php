<?php

namespace App\Doctrine;

use App\Entity\TaskSection;

/**
 * Scopes TaskSection queries to spaces the current user belongs to. Uses the
 * denormalised `space` FK so the shared direct-or-via-group membership
 * predicate works without joining through `project`. See
 * {@see AbstractSpaceAccessExtension}.
 */
final class TaskSectionAccessExtension extends AbstractSpaceAccessExtension
{
    protected function getResourceClass(): string
    {
        return TaskSection::class;
    }

    protected function getAliasPrefix(): string
    {
        return 'task_section_access';
    }

    protected function getImpersonationItemType(): ?string
    {
        // Sections follow their project's space + the 'projects' category
        // default; they aren't individually addressable for per-item overrides.
        return null;
    }
}
