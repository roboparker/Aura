<?php

declare(strict_types=1);

namespace App\Service;

use App\Deletion\SoftDeletionService;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Repository\SpaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Space deletion with the same grace period as organizations and accounts.
 *
 * `DELETE /spaces/{id}` used to remove the row on the spot, taking every board,
 * task, page, comment and attachment in it — other people's work, gone on one
 * admin's confirm with no undo. The endpoint is unchanged from the caller's
 * side; what it does now is schedule.
 */
final class SpaceDeletionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpaceRepository $spaces,
        private SoftDeletionService $softDeletion,
        private SpaceExportRequester $exportRequester,
        private LoggerInterface $logger,
    ) {
    }

    /** Begin deletion; returns when the space becomes purgeable. */
    public function softDelete(Space $space, User $requestedBy): \DateTimeImmutable
    {
        $purgeAfter = $this->softDeletion->schedule($space, $this->admins($space));

        // Archive the contents while they still exist. Best-effort — a failed
        // export must not block a deletion the admin asked for.
        try {
            $this->exportRequester->request($space, $requestedBy);
        } catch (\Throwable $e) {
            $this->logger->error('Could not queue export for space {space} on deletion: {error}', [
                'space' => (string) $space->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $purgeAfter;
    }

    public function restore(Space $space): bool
    {
        if (!$space->isDeleted()) {
            return false;
        }
        $space->clearDeleted();
        $this->softDeletion->retireTokens($space);
        $this->em->flush();

        return true;
    }

    /**
     * Hard-delete spaces past their window. Returns how many went.
     *
     * A space whose organization is *also* mid-deletion is skipped: the org
     * purge will take it, and removing it early would strand the org's own
     * export and make its restore link bring back an empty shell.
     */
    public function purgeDue(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $purged = 0;

        foreach ($this->spaces->findDueForPurge($now) as $space) {
            $organization = $space->getOrganization();
            if (null !== $organization && $organization->isDeleted()) {
                continue;
            }

            $id = (string) $space->getId();
            try {
                $this->softDeletion->retireTokens($space);
                $this->em->remove($space);
                $this->em->flush();
                ++$purged;
                $this->logger->info('Purged space {space} after its deletion grace period.', ['space' => $id]);
            } catch (\Throwable $e) {
                // One bad row must not stall the queue behind it; it stays due
                // and the next run retries.
                $this->logger->error('Failed to purge space {space}: {error}', [
                    'space' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $purged;
    }

    /**
     * The space's admins — everyone who could have taken this decision, and so
     * everyone who should get the chance to reverse it.
     *
     * @return list<User>
     */
    private function admins(Space $space): array
    {
        $out = [];
        $seen = [];
        foreach ($space->getUserMemberships() as $membership) {
            if (Space::ROLE_ADMIN !== $membership->getRole()) {
                continue;
            }
            $this->collect($membership, $out, $seen);
        }

        return $out;
    }

    /**
     * @param list<User>          $out
     * @param array<string, true> $seen
     */
    private function collect(SpaceMembership $membership, array &$out, array &$seen): void
    {
        $user = $membership->getUser();
        $id = (string) $user?->getId();
        if (null === $user || '' === $id || isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $out[] = $user;
    }
}
