<?php

declare(strict_types=1);

namespace App\Deletion;

/**
 * Something that can be deleted with a grace period rather than destroyed on
 * the spot: an {@see \App\Entity\Organization}, a {@see \App\Entity\Space}, or
 * a {@see \App\Entity\User} account.
 *
 * All three are irreversible in practice — an org or space cascades to
 * everything inside it, and an account reassigns its authorship to a sentinel —
 * so all three get the same treatment: a stamped window during which the thing
 * is inaccessible but intact, an emailed restore link, and a nightly purge that
 * does the real delete once the window lapses.
 *
 * The interface exists so {@see SoftDeletionService} and {@see PurgeRunner} can
 * treat the three uniformly. What differs per type — cancelling a
 * subscription, reassigning authorship, blocking sign-in — stays in the
 * type's own service.
 */
interface SoftDeletable
{
    /** Discriminator used in restore tokens and email copy. */
    public function deletionTargetType(): string;

    /** What to call this in an email: an org/space name, or an account email. */
    public function deletionLabel(): string;

    public function getDeletedAt(): ?\DateTimeImmutable;

    /**
     * When the purge may run. Stored rather than derived from `deletedAt + N`
     * so shortening the configured window can never retroactively bring forward
     * a deletion someone was already promised.
     */
    public function getPurgeAfter(): ?\DateTimeImmutable;

    public function isDeleted(): bool;

    public function markDeleted(\DateTimeImmutable $at, \DateTimeImmutable $purgeAfter): static;

    public function clearDeleted(): static;
}
