<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Repository\RestoreTokenRepository;
use App\Service\AccountDeletionService;
use App\Service\OrganizationDeletionService;
use App\Service\SpaceDeletionService;

/**
 * The nightly sweep that turns lapsed grace periods into real deletions.
 *
 * Order matters. Organizations go first because purging one takes its spaces
 * with it — doing spaces first would work through rows that are about to be
 * cascaded anyway. Accounts go last: an account purge reassigns its authorship
 * to the sentinel, and doing that before its org/space deletions have run would
 * leave the sentinel owning content that is itself about to disappear.
 *
 * Each stage is individually fault-tolerant (a failure is logged and the row
 * stays due), so one poisoned record can't stop the others.
 */
final class PurgeRunner
{
    public function __construct(
        private OrganizationDeletionService $organizations,
        private SpaceDeletionService $spaces,
        private AccountDeletionService $accounts,
        private RestoreTokenRepository $tokens,
    ) {
    }

    /**
     * @return array{organizations: int, spaces: int, accounts: int, tokens: int}
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $result = [
            'organizations' => $this->organizations->purgeDue($now),
            'spaces' => $this->spaces->purgeDue($now),
            'accounts' => $this->accounts->purgeDue($now),
            'tokens' => 0,
        ];

        // Sweep restore tokens whose window has passed regardless of what
        // happened to their target — a token that can no longer restore
        // anything is just a hash sitting in a table.
        $result['tokens'] = $this->tokens->pruneExpired($now);

        return $result;
    }
}
