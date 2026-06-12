<?php

namespace App\Service;

use App\Entity\User;

/**
 * Outcome of a {@see WaitlistGrantService::grant()} batch: who was actually
 * promoted (was waitlisted, now a full member + emailed) versus who was a
 * no-op (already had full access, so no flag flip and no email).
 */
final class WaitlistGrantResult
{
    /**
     * @param list<User> $promoted users that were waitlisted and are now full members
     * @param list<User> $skipped  users that already had full access (no-op, no email)
     */
    public function __construct(
        public readonly array $promoted,
        public readonly array $skipped,
    ) {
    }
}
