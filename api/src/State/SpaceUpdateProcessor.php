<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Space;
use App\Entity\User;
use App\Repository\SpaceInviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Side effects for `PATCH /spaces/{id}`. Today the only one is the
 * shared → private transition: a private space is single-occupant by
 * definition, so flipping the toggle has to clean up every existing
 * non-creator membership (direct users + the space's groups) and revoke
 * every pending invite. The reverse (private → shared) needs no cleanup —
 * there's nothing to remove — so the persist processor handles it
 * directly.
 *
 * Visibility flips are also gated to the creator. The standard Patch
 * security expression lets any admin edit metadata, but "make this
 * space invite-only" is a structural privilege equivalent to delete:
 * if a non-creator admin could do it they'd accidentally kick
 * themselves out of the space. Keeping it creator-only matches the
 * "private = owner-only" mental model the toggle is supposed to express.
 *
 * @implements ProcessorInterface<Space, Space>
 */
final class SpaceUpdateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Space, Space> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private EntityManagerInterface $em,
        private SpaceInviteRepository $spaceInviteRepository,
        private Security $security,
    ) {
    }

    /**
     * @param Space $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Space
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to edit a space.');
        }

        $originalVisibility = $this->originalVisibility($data);
        $newVisibility = $data->getVisibility();

        if ($originalVisibility !== $newVisibility) {
            $creatorId = $data->getCreatedBy()?->getId();
            if (null === $creatorId || !$creatorId->equals($user->getId())) {
                throw new AccessDeniedHttpException('Only the space creator can change visibility.');
            }

            if (
                Space::VISIBILITY_SHARED === $originalVisibility
                && Space::VISIBILITY_PRIVATE === $newVisibility
            ) {
                $this->kickEveryoneButCreator($data);
                $this->revokePendingInvites($data);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Read the visibility as it was when the entity was hydrated, before
     * API Platform applied the request body. We can't compare against
     * `$data->getVisibility()` because by the time the processor runs
     * the new value has already been denormalized onto the entity.
     */
    private function originalVisibility(Space $space): string
    {
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($space);
        $value = $original['visibility'] ?? null;
        return is_string($value) ? $value : Space::VISIBILITY_SHARED;
    }

    /**
     * Remove every direct membership that isn't the creator's, and delete
     * the space's groups outright — a private space is single-occupant, and
     * a group's members would otherwise transitively re-enter the space.
     * orphanRemoval on userMemberships turns the detach into a delete on
     * flush; group deletion cascades its memberships + invites. Snapshotting
     * the collections up-front avoids iterate-while-mutating issues.
     */
    private function kickEveryoneButCreator(Space $space): void
    {
        $creatorId = $space->getCreatedBy()?->getId();

        $userMemberships = $space->getUserMemberships()->toArray();
        foreach ($userMemberships as $membership) {
            $rowUserId = $membership->getUser()?->getId();
            if (null === $rowUserId || null === $creatorId || !$rowUserId->equals($creatorId)) {
                $space->removeUserMembership($membership);
            }
        }

        foreach ($space->getGroups()->toArray() as $group) {
            $this->em->remove($group);
        }
    }

    /**
     * Drop every pending SpaceInvite attached to this space and, when
     * that was the last attachment on the parent UserInvite, drop the
     * parent too — matching {@see \App\Controller\SpaceInviteController::revoke()}
     * so a token can't be redeemed to nothing.
     */
    private function revokePendingInvites(Space $space): void
    {
        $invites = $this->spaceInviteRepository->findActiveBySpace($space);
        if ([] === $invites) {
            return;
        }

        $parents = [];
        foreach ($invites as $invite) {
            $parent = $invite->getUserInvite();
            $parentId = $parent->getId();
            if (null !== $parentId) {
                $parents[(string) $parentId] = $parent;
            }
            $this->em->remove($invite);
        }
        $this->em->flush();

        foreach ($parents as $parent) {
            $this->em->refresh($parent);
            if (
                0 === $parent->getGroupInvites()->count()
                && 0 === $parent->getSpaceInvites()->count()
            ) {
                $this->em->remove($parent);
            }
        }
        $this->em->flush();
    }
}
