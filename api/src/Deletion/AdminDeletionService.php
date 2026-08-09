<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\AdminActionLog;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\User;
use App\Service\AccountDeletionService;
use App\Service\AdminDeletionNoticeMailer;
use App\Service\OrganizationDeletionService;
use App\Service\SpaceDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Site-admin deletion of somebody else's account, organization, or space.
 *
 * Two modes, and the distinction is the whole point of this class:
 *
 * - **Scheduled** goes through exactly the same grace period as a self-service
 *   delete, restore link and all. This is the right default even for an admin:
 *   it's reversible, and most admin deletions are cleanup rather than
 *   emergencies.
 * - **Immediate** skips the window and purges now. Deliberately available *only*
 *   here and never to end users — it exists for erasure requests that can't wait
 *   and for abuse takedowns where leaving content live for 30 days isn't an
 *   option. There is no undo.
 *
 * Every call writes an {@see AdminActionLog} row **before** the destructive
 * work, so an action that dies half-way still leaves a trace of what was
 * attempted. Notifying the owner is the admin's per-action choice: someone who
 * requested erasure deserves the confirmation, while warning a spam account
 * before you finish removing it is unhelpful.
 */
final class AdminDeletionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private SoftDeletionService $softDeletion,
        private OrganizationDeletionService $organizations,
        private SpaceDeletionService $spaces,
        private AccountDeletionService $accounts,
        private AdminDeletionNoticeMailer $mailer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * What an admin would be destroying if they removed this user: the things
     * that would otherwise be left ownerless or silently reassigned.
     *
     * Surfaced *before* the action so the blast radius is a decision rather
     * than a surprise — the admin ticks what goes with the account, and the
     * default (nothing) keeps the existing promote-or-archive behaviour.
     *
     * @return array{organizations: list<array{id: string, name: string, memberCount: int}>,
     *               spaces: list<array{id: string, name: string, memberCount: int}>}
     */
    public function assetsOf(User $user): array
    {
        $organizations = [];
        foreach ($this->em->getRepository(Organization::class)->findAll() as $organization) {
            if ($organization->isDeleted() || !$this->isSoleOwner($organization, $user)) {
                continue;
            }
            $organizations[] = [
                'id' => (string) $organization->getId(),
                'name' => $organization->getName(),
                'memberCount' => count($organization->getMemberships()),
            ];
        }

        $spaces = [];
        foreach ($this->em->getRepository(Space::class)->findBy(['isPersonal' => false]) as $space) {
            // Personal spaces are excluded: they go with the account by
            // definition and aren't a choice the admin gets to make.
            if ($space->isDeleted() || null !== $space->getOrganization() || !$this->isSoleAdmin($space, $user)) {
                continue;
            }
            $spaces[] = [
                'id' => (string) $space->getId(),
                'name' => $space->getName(),
                'memberCount' => count($space->getUserMemberships()),
            ];
        }

        return ['organizations' => $organizations, 'spaces' => $spaces];
    }

    /**
     * Delete a target. `$targetType` is one of {@see SoftDeletionService}'s
     * TYPE_* constants. Returns the audit row.
     *
     * @throws \InvalidArgumentException when the target can't be resolved
     */
    public function delete(
        SoftDeletable $target,
        User $actor,
        bool $immediate,
        bool $notifyOwner,
        string $reason,
    ): AdminActionLog {
        $owner = $this->ownerOf($target);

        // Logged first: an action that fails part-way should still leave a
        // record that it was attempted, against this actor, for this reason.
        $log = new AdminActionLog(
            $actor,
            $actor->getEmail(),
            $immediate ? AdminActionLog::ACTION_DELETE_IMMEDIATE : AdminActionLog::ACTION_DELETE_SCHEDULED,
            $target->deletionTargetType(),
            $this->idOf($target),
            $target->deletionLabel(),
            $reason,
        );
        $log->setTargetOwnerEmail($owner?->getEmail());
        $this->em->persist($log);
        $this->em->flush();

        // Notify before the immediate branch runs — once purged, a User target
        // no longer has an address to send to.
        if ($notifyOwner && null !== $owner) {
            try {
                $this->mailer->sendAdminDeletionNotice($owner, $target, $immediate, $reason);
                $log->setOwnerNotified(true);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->error('Could not notify {email} of an admin deletion: {error}', [
                    'email' => $owner->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($immediate) {
            $this->purgeNow($target);
        } else {
            $this->schedule($target, $actor);
        }

        $this->logger->warning(
            'Admin {actor} {action} {type} "{label}": {reason}',
            [
                'actor' => $actor->getEmail(),
                'action' => $immediate ? 'permanently deleted' : 'scheduled deletion of',
                'type' => $target->deletionTargetType(),
                'label' => $target->deletionLabel(),
                'reason' => $reason,
            ],
        );

        return $log;
    }

    /** Route to the type's own scheduler so the usual side effects still run. */
    private function schedule(SoftDeletable $target, User $actor): void
    {
        if ($target instanceof Organization) {
            $this->organizations->softDelete($target);

            return;
        }
        if ($target instanceof Space) {
            $this->spaces->softDelete($target, $actor);

            return;
        }
        if ($target instanceof User) {
            $this->accounts->scheduleDeletion($target);

            return;
        }

        throw new \InvalidArgumentException('Unsupported deletion target.');
    }

    /**
     * Skip the window entirely. Any outstanding restore token is retired first
     * — a target deleted normally and then force-purged by an admin must not
     * leave a live link behind.
     */
    private function purgeNow(SoftDeletable $target): void
    {
        $this->softDeletion->retireTokens($target);

        if ($target instanceof Organization) {
            $this->organizations->purge($target);

            return;
        }
        if ($target instanceof Space) {
            $this->em->remove($target);
            $this->em->flush();

            return;
        }
        if ($target instanceof User) {
            $this->accounts->deleteAccount($target);

            return;
        }

        throw new \InvalidArgumentException('Unsupported deletion target.');
    }

    /** Who to notify: the org's first owner, the space's creator, or the user. */
    private function ownerOf(SoftDeletable $target): ?User
    {
        if ($target instanceof User) {
            return $target;
        }
        if ($target instanceof Space) {
            return $target->getCreatedBy();
        }
        if ($target instanceof Organization) {
            foreach ($target->getMemberships() as $membership) {
                if (Organization::ROLE_OWNER === $membership->getRole()) {
                    return $membership->getUser();
                }
            }
        }

        return null;
    }

    private function idOf(SoftDeletable $target): \Symfony\Component\Uid\Uuid
    {
        $id = match (true) {
            $target instanceof Organization, $target instanceof Space, $target instanceof User => $target->getId(),
            default => null,
        };
        if (null === $id) {
            throw new \InvalidArgumentException('Target has no identity.');
        }

        return $id;
    }

    private function isSoleOwner(Organization $organization, User $user): bool
    {
        $owners = [];
        foreach ($organization->getMemberships() as $membership) {
            if (Organization::ROLE_OWNER === $membership->getRole()) {
                $owners[] = (string) $membership->getUser()?->getId();
            }
        }

        return [(string) $user->getId()] === array_values(array_unique($owners));
    }

    private function isSoleAdmin(Space $space, User $user): bool
    {
        $admins = [];
        foreach ($space->getUserMemberships() as $membership) {
            if (Space::ROLE_ADMIN === $membership->getRole()) {
                $admins[] = (string) $membership->getUser()?->getId();
            }
        }

        return [(string) $user->getId()] === array_values(array_unique($admins));
    }
}
