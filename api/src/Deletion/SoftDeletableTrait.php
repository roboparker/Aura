<?php

declare(strict_types=1);

namespace App\Deletion;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The two columns and the state transitions behind {@see SoftDeletable}.
 *
 * Both are server-written only — never in a `:write` serialization group.
 * Deletion goes through {@see SoftDeletionService} so the restore token and
 * the notice email can't be skipped by writing the column directly.
 *
 * Serialization groups are listed for all three consumers because a trait
 * can't know which entity mixes it in, and an unknown group on an entity is
 * simply ignored.
 */
trait SoftDeletableTrait
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['organization:read', 'space:read'])]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['organization:read', 'space:read'])]
    private ?\DateTimeImmutable $purgeAfter = null;

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getPurgeAfter(): ?\DateTimeImmutable
    {
        return $this->purgeAfter;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function markDeleted(\DateTimeImmutable $at, \DateTimeImmutable $purgeAfter): static
    {
        $this->deletedAt = $at;
        $this->purgeAfter = $purgeAfter;
        $this->touchOnDeletionChange();

        return $this;
    }

    public function clearDeleted(): static
    {
        $this->deletedAt = null;
        $this->purgeAfter = null;
        $this->touchOnDeletionChange();

        return $this;
    }

    /**
     * Hook for entities that carry an `updatedAt` they want bumped. Default is
     * a no-op so entities without one don't have to care.
     */
    protected function touchOnDeletionChange(): void
    {
    }
}
