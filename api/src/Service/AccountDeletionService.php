<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Billing\BillingException;
use App\Billing\StripeGatewayInterface;
use App\Deletion\SoftDeletionService;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hard-deletes a user account while preserving the content they authored.
 *
 * Every authorship FK on User is `onDelete: CASCADE` and non-null, so a naive
 * `EntityManager::remove($user)` would erase their tasks, boards, pages,
 * comments, feedback tickets and media. Instead we reassign those FKs to a
 * reserved placeholder inside a transaction *before* removing the user, so the
 * content stays published under an anonymized author. Memberships, API tokens,
 * sessions, notifications, invites and personal tags clear via their existing
 * CASCADE.
 *
 * The reassignment itself — which FKs, and which placeholder — lives in
 * {@see AuthorshipSentinel}, shared with {@see AgentDeletionService} so the two
 * deletion paths cannot drift as new authored entities are added.
 */
final class AccountDeletionService
{
    /**
     * @deprecated Use {@see AuthorshipSentinel::HUMAN_EMAIL}. Kept as an alias
     *             because this constant is referenced from tests and reads
     *             naturally at those call sites.
     */
    public const SENTINEL_EMAIL = AuthorshipSentinel::HUMAN_EMAIL;

    public function __construct(
        private EntityManagerInterface $em,
        private SubscriptionRepository $subscriptions,
        private StripeGatewayInterface $stripe,
        private SoftDeletionService $softDeletion,
        private PersonalOrganizationProvisioner $personalOrganizations,
        private AuthorshipSentinel $authorship,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Begin deletion: stamp the grace window and email the restore link.
     * Returns when the account becomes purgeable.
     *
     * Billing is cancelled now rather than at purge — the user asked to leave,
     * and charging them through a 30-day window they're locked out of would be
     * indefensible. If they restore, they re-subscribe.
     */
    public function scheduleDeletion(User $user): \DateTimeImmutable
    {
        // Either placeholder — deleting one would cascade away every orphaned
        // piece of content that had been reassigned to it, which is exactly
        // what the reassignment existed to prevent.
        if ($this->authorship->isSentinel($user)) {
            throw new \RuntimeException('The system sentinel account cannot be deleted.');
        }

        $this->cancelPersonalSubscriptions($user);

        // Only the account holder — nobody else has standing to reverse this,
        // and it's their address on the account.
        return $this->softDeletion->schedule($user, [$user]);
    }

    public function restore(User $user): bool
    {
        if (!$user->isDeleted()) {
            return false;
        }
        $user->clearDeleted();
        $this->softDeletion->retireTokens($user);
        $this->em->flush();

        return true;
    }

    /**
     * Hard-delete accounts past their window. Returns how many went.
     */
    public function purgeDue(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $purged = 0;

        foreach ($this->dueForPurge($now) as $user) {
            $id = (string) $user->getId();
            try {
                $this->softDeletion->retireTokens($user);
                $this->deleteAccount($user);
                ++$purged;
                $this->logger->info('Purged account {user} after its deletion grace period.', ['user' => $id]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to purge account {user}: {error}', [
                    'user' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $purged;
    }

    /**
     * Accounts whose grace period has lapsed, oldest first.
     *
     * @return list<User>
     */
    private function dueForPurge(\DateTimeImmutable $now, int $limit = 50): array
    {
        /** @var list<User> $users */
        $users = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.deletedAt IS NOT NULL')
            ->andWhere('u.purgeAfter IS NOT NULL')
            ->andWhere('u.purgeAfter <= :now')
            ->setParameter('now', $now)
            ->orderBy('u.purgeAfter', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $users;
    }

    /**
     * The irreversible part: reassign authorship to the sentinel, then remove
     * the row. Called by the purge once the grace period lapses — not from the
     * request path.
     */
    public function deleteAccount(User $user): void
    {
        if ($this->authorship->isSentinel($user)) {
            throw new \RuntimeException('The system sentinel account cannot be deleted.');
        }

        // Stop billing the departing user's card BEFORE we delete the account.
        // The personal Subscription rows are `onDelete: CASCADE` on ownerUser,
        // so the transaction below would erase our mirror while leaving the
        // Stripe subscription live and renewing. Cancel immediately at Stripe
        // first; the resulting customer.subscription.deleted webhook is a
        // harmless no-op once the row has cascaded away.
        $this->cancelPersonalSubscriptions($user);

        $this->em->wrapInTransaction(function () use ($user): void {
            $userId = (string) $user->getId();

            // Reassign authored content to the placeholder for this kind of
            // subject. `sentinelFor()` rather than a fixed account because the
            // site-admin deletion endpoint can target any user row, including
            // an agent — and an agent's output must not end up filed under
            // "Former member", which reads as a colleague.
            $this->authorship->reassign($user, $this->authorship->sentinelFor($user));

            $this->handleSpaces($user, $userId);
            $this->handlePersonalOrganization($user);

            $managed = $this->em->find(User::class, $userId);
            if (null !== $managed) {
                // task_assignee rows clear via CASCADE → the user is dropped
                // from every task's assignee list ("unassigned").
                $this->em->remove($managed);
            }
        });
    }

    /**
     * Cancel every still-live personal subscription the user owns at Stripe.
     * Best-effort and outside the delete transaction: a Stripe outage logs a
     * warning but must never block a GDPR account deletion — worst case an
     * operator cancels the stray subscription from the dashboard.
     */
    private function cancelPersonalSubscriptions(User $user): void
    {
        if (!$this->stripe->isConfigured()) {
            return;
        }

        foreach ($this->subscriptions->findCancelableForUser($user) as $subscription) {
            $stripeId = $subscription->getStripeSubscriptionId();
            if (null === $stripeId || '' === $stripeId) {
                continue;
            }
            try {
                $this->stripe->cancelSubscription($stripeId);
            } catch (BillingException $e) {
                $this->logger->warning('Failed to cancel Stripe subscription {sub} on account deletion: {error}', [
                    'sub' => $stripeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Personal space → deleted with the user. Shared space the user solely
     * admins → promote the next member, or delete it if empty.
     */
    private function handleSpaces(User $user, string $userId): void
    {
        $memberships = $this->em->getRepository(SpaceMembership::class)->findBy(['user' => $user]);
        foreach ($memberships as $membership) {
            $space = $membership->getSpace();
            if (null === $space) {
                continue;
            }
            if ($space->getIsPersonal()) {
                $this->em->remove($space);
                continue;
            }
            if (Space::ROLE_ADMIN !== $membership->getRole()) {
                continue;
            }

            $otherAdmins = [];
            $otherMembers = [];
            foreach ($space->getUserMemberships() as $other) {
                if ((string) $other->getUser()?->getId() === $userId) {
                    continue;
                }
                $otherMembers[] = $other;
                if (Space::ROLE_ADMIN === $other->getRole()) {
                    $otherAdmins[] = $other;
                }
            }

            if ([] !== $otherAdmins) {
                continue;
            }
            if ([] !== $otherMembers) {
                $otherMembers[0]->setRole(Space::ROLE_ADMIN);
            } else {
                $this->em->remove($space);
            }
        }
    }

    /**
     * The user's personal organization is their own account — it exists only to
     * own their spaces and hold their plan, so it goes with them (#billing
     * Phase 2). Shared organizations they merely belonged to are untouched;
     * their membership row clears via CASCADE, and the account itself outlives
     * them.
     */
    private function handlePersonalOrganization(User $user): void
    {
        $personal = $this->em->getRepository(Organization::class)
            ->findOneBy(['createdBy' => $user, 'isPersonal' => true]);
        if (null === $personal) {
            return;
        }

        // Spaces the departing user created but *shared* also live in their
        // personal organization, and removing it CASCADEs. Without this, a
        // shared space would be destroyed along with everything its other
        // members wrote — silently defeating the promote-or-archive rule
        // handleSpaces() just applied, one layer up.
        //
        // So each survivor follows its new admin into *their* personal account.
        // Runs after handleSpaces(), which has already done the promotion, so
        // "who inherits this" is a question that already has an answer.
        foreach ($this->em->getRepository(Space::class)->findBy(['organization' => $personal]) as $space) {
            if ($space->getIsPersonal()) {
                // The "Private" space is an artefact of the account; it goes.
                continue;
            }
            $heir = $this->survivingAdmin($space, $user);
            if (null === $heir) {
                // Nobody left to inherit it — cascading with the org is the
                // same outcome handleSpaces() reaches for an empty space.
                continue;
            }
            $space->setOrganization($this->personalOrganizations->provision($heir));
        }

        $this->em->remove($personal);
    }

    /**
     * Whoever should inherit a shared space: its admin other than the departing
     * user, falling back to any remaining member. Null when nobody is left.
     */
    private function survivingAdmin(Space $space, User $leaving): ?User
    {
        $leavingId = (string) $leaving->getId();
        $fallback = null;

        foreach ($space->getUserMemberships() as $membership) {
            $candidate = $membership->getUser();
            if (null === $candidate || (string) $candidate->getId() === $leavingId) {
                continue;
            }
            if (Space::ROLE_ADMIN === $membership->getRole()) {
                return $candidate;
            }
            $fallback ??= $candidate;
        }

        return $fallback;
    }
}
