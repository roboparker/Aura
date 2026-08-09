<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\Space;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Service\PersonalOrganizationProvisioner;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Guarantees the Phase 2 invariant — **every space belongs to an
 * organization** — for persistence paths that don't go through
 * {@see \App\State\SpaceCreateProcessor}.
 *
 * The processor covers the API. Nothing covered tests, fixtures, or a future
 * CLI, and `new Space()` + persist happily produced a space with no account
 * behind it. That was harmless while subscription resolution had a
 * creator-account fallback; it stopped being harmless when that collapsed to a
 * single lookup, and it becomes a hard NOT NULL failure in the next step. This
 * is what makes that constraint safe to add.
 *
 * **Why `onFlush` and not `prePersist`.** The creator may have no personal
 * organization yet — in a test both the user and the space are often persisted
 * in the same flush — so the listener has to be able to *create* one. An
 * `EntityManager::persist()` during `prePersist` is too late to be picked up by
 * the flush already in progress; `onFlush` with an explicit
 * `computeChangeSet()` is the supported way to introduce an entity mid-flush.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class SpaceOrganizationDefaultListener
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly PersonalOrganizationProvisioner $provisioner,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var list<Space> $orphans */
        $orphans = [];
        /** @var array<string, Organization> $pending personal orgs already in this flush */
        $pending = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Space && null === $entity->getOrganization() && null !== $entity->getCreatedBy()) {
                $orphans[] = $entity;
                continue;
            }
            // A personal org created earlier in this same flush (the signup
            // path) must be reused rather than duplicated — the partial unique
            // index would reject the second one.
            if ($entity instanceof Organization && $entity->getIsPersonal()) {
                $ownerId = $this->idOf($entity->getCreatedBy());
                if (null !== $ownerId) {
                    $pending[$ownerId] = $entity;
                }
            }
        }

        if ([] === $orphans) {
            return;
        }

        $spaceMeta = $em->getClassMetadata(Space::class);

        foreach ($orphans as $space) {
            $creator = $space->getCreatedBy();
            $creatorId = $this->idOf($creator);
            if (null === $creator || null === $creatorId) {
                continue;
            }

            $organization = $pending[$creatorId] ?? $this->organizations->findPersonalFor($creator);
            if (null === $organization) {
                $organization = $this->create($em, $uow, $creator);
                $pending[$creatorId] = $organization;
            }

            $space->setOrganization($organization);
            // The change set for this insert was computed before we touched it,
            // so it has to be recomputed or the FK simply isn't written.
            $uow->recomputeSingleEntityChangeSet($spaceMeta, $space);
        }
    }

    /**
     * Build and register a personal organization plus its owner membership
     * inside the running flush.
     */
    private function create(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        User $user,
    ): Organization {
        $organization = $this->provisioner->build($user);

        $em->persist($organization);
        $uow->computeChangeSet($em->getClassMetadata(Organization::class), $organization);

        // Cascades don't re-run for entities introduced during onFlush, so the
        // membership the builder attached has to be registered by hand.
        $membershipMeta = $em->getClassMetadata(OrganizationMembership::class);
        foreach ($organization->getMemberships() as $membership) {
            $em->persist($membership);
            $uow->computeChangeSet($membershipMeta, $membership);
        }

        return $organization;
    }

    private function idOf(?User $user): ?string
    {
        $id = $user?->getId();

        return null === $id ? null : (string) $id;
    }
}
