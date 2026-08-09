<?php

declare(strict_types=1);

namespace App\Service;

use App\Billing\BillingException;
use App\Billing\StripeGatewayInterface;
use App\Deletion\SoftDeletionService;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Deleting an organization, in two stages.
 *
 * Hard-deleting immediately was the obvious design and the wrong one: an org's
 * spaces cascade, so one owner clicking delete would destroy every board, task,
 * page and comment every other member had ever written, with nothing to undo it
 * — the single most destructive action in the product, gated by one dialog.
 *
 * So deletion is a **state**, not an event:
 *
 *  1. {@see softDelete()} stamps `deletedAt` + `purgeAfter`, cancels billing
 *     immediately (nobody should pay through a grace period they asked to end)
 *     and queues a data export of every space so the content is retrievable
 *     even after the purge. Access stops at once — the access extensions treat
 *     a soft-deleted org's spaces as gone, so members aren't left working in
 *     something scheduled to vanish.
 *  2. {@see purge()} runs from the nightly job once the window lapses and does
 *     the real delete, letting the `space.organization` CASCADE run.
 *
 * {@see restore()} reverses stage 1 for as long as stage 2 hasn't run. It
 * deliberately does *not* resurrect the subscription: that money movement
 * should be a decision someone makes again, not a side effect of undo.
 */
final class OrganizationDeletionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrganizationRepository $organizations,
        private StripeGatewayInterface $stripe,
        private SpaceExportRequester $exportRequester,
        private SoftDeletionService $softDeletion,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Begin deletion. Returns the instant the org becomes purgeable, which is
     * what the caller shows the user ("restorable until …").
     */
    public function softDelete(Organization $organization): \DateTimeImmutable
    {
        if ($organization->isDeleted()) {
            $existing = $organization->getPurgeAfter();
            if (null !== $existing) {
                return $existing;
            }
        }

        // Stop the money first. Doing this before the state change means a
        // Stripe failure surfaces while the org is still live and the owner can
        // retry, rather than after we've already told them it's deleted.
        $this->cancelSubscriptions($organization);

        // Shared half: stamp the window, mint the restore token, email the
        // link. Every owner gets it, not just whoever clicked delete — the
        // others didn't ask for this and need a way to undo it.
        $purgeAfter = $this->softDeletion->schedule($organization, $this->owners($organization));

        // Best-effort: an export that fails to queue must not fail the
        // deletion, but it's the only copy of the data that outlives the purge,
        // so a failure is worth a log line loud enough to notice.
        $this->requestExports($organization);

        return $purgeAfter;
    }

    /**
     * Everyone who should hear that the org is going away: its owners, deduped.
     *
     * @return list<User>
     */
    private function owners(Organization $organization): array
    {
        $out = [];
        $seen = [];
        foreach ($organization->getMemberships() as $membership) {
            if (Organization::ROLE_OWNER !== $membership->getRole()) {
                continue;
            }
            $user = $membership->getUser();
            $id = (string) $user?->getId();
            if (null === $user || '' === $id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $user;
        }

        return $out;
    }

    /**
     * Undo a soft delete. Returns false when the org isn't in the grace period
     * (already purged, or never deleted).
     */
    public function restore(Organization $organization): bool
    {
        if (!$organization->isDeleted()) {
            return false;
        }

        $organization->clearDeleted();
        // Retire the emailed link: the decision has been made in-app, and a
        // still-live token would let a later click re-restore something that
        // has since been deleted again.
        $this->softDeletion->retireTokens($organization);
        $this->em->flush();

        return true;
    }

    /**
     * Hard-delete every organization whose grace period has lapsed. Returns the
     * number purged. Idempotent — a re-run finds nothing left.
     */
    public function purgeDue(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $purged = 0;

        foreach ($this->organizations->findDueForPurge($now) as $organization) {
            $id = (string) $organization->getId();
            try {
                $this->purge($organization);
                ++$purged;
                $this->logger->info('Purged organization {org} after its deletion grace period.', ['org' => $id]);
            } catch (\Throwable $e) {
                // One poisoned org must not stall the queue behind it — the row
                // stays due and the next run retries it.
                $this->logger->error('Failed to purge organization {org}: {error}', [
                    'org' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $purged;
    }

    /**
     * Hard-delete one organization: its spaces (and everything in them) go with
     * it via the `space.organization` CASCADE, as do its memberships.
     *
     * Subscription rows are detached rather than cascaded — the billing history
     * of what an account paid should outlive the account, and the row is
     * already terminal because softDelete() cancelled it.
     */
    public function purge(Organization $organization): void
    {
        // The restore link dies with the thing it pointed at.
        $this->softDeletion->retireTokens($organization);

        $this->em->wrapInTransaction(function () use ($organization): void {
            $this->em->createQueryBuilder()
                ->update(Subscription::class, 's')
                ->set('s.organization', ':null')
                ->where('s.organization = :org')
                ->setParameter('null', null)
                ->setParameter('org', $organization)
                ->getQuery()
                ->execute();

            $this->em->remove($organization);
        });
    }

    /**
     * Cancel the org's live subscriptions at Stripe, immediately. Best-effort:
     * a Stripe outage logs a warning rather than blocking the deletion — worst
     * case an operator cancels the stray subscription from the dashboard, which
     * is recoverable, whereas an owner who can't delete their account is not.
     */
    private function cancelSubscriptions(Organization $organization): void
    {
        if (!$this->stripe->isConfigured()) {
            return;
        }

        $subscription = $this->em->getRepository(Subscription::class)->findOneBy([
            'organization' => $organization,
        ]);
        $stripeId = $subscription?->getStripeSubscriptionId();
        if (null === $subscription || null === $stripeId || '' === $stripeId) {
            return;
        }

        try {
            $this->stripe->cancelSubscription($stripeId);
        } catch (BillingException $e) {
            $this->logger->warning('Failed to cancel Stripe subscription {sub} on org deletion: {error}', [
                'sub' => $stripeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Queue a data export for each of the org's spaces. */
    private function requestExports(Organization $organization): void
    {
        $requester = $organization->getCreatedBy();
        foreach ($this->em->getRepository(Space::class)->findBy(['organization' => $organization]) as $space) {
            $owner = $requester ?? $space->getCreatedBy();
            if (null === $owner) {
                continue;
            }
            try {
                $this->exportRequester->request($space, $owner);
            } catch (\Throwable $e) {
                $this->logger->error('Could not queue export for space {space} on org deletion: {error}', [
                    'space' => (string) $space->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
