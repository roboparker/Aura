<?php

namespace App\Controller;

use App\Entity\Space;
use App\Entity\User;
use App\Repository\SpaceInviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Read and revoke pending space invites (#186). Mirrors the
 * group-side {@see UserInviteController}.
 *
 *   GET    /spaces/{id}/invites                   — admin-only; lists
 *       active SpaceInvites for the space so admins can see who
 *       hasn't signed up yet.
 *
 *   DELETE /spaces/{id}/invites/{spaceInviteId}   — admin-only;
 *       revokes a single SpaceInvite. If it was the last attachment
 *       (group OR space) on its parent UserInvite, the parent is
 *       removed too — no point keeping a token that buys access to
 *       nothing.
 */
class SpaceInviteController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpaceInviteRepository $spaceInviteRepository,
    ) {
    }

    #[Route('/spaces/{id}/invites', name: 'space_invite_list', methods: ['GET'])]
    public function list(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$space->isAdmin($user)) {
            if (!$space->hasMember($user)) {
                return $this->json(['error' => 'Space not found.'], 404);
            }
            return $this->json(['error' => 'Only space admins can view invites.'], 403);
        }

        $payload = array_map(fn ($si) => [
            'id' => (string) $si->getId(),
            'email' => $si->getUserInvite()->getEmail(),
            'invitedBy' => $si->getInvitedBy()->getEmail(),
            'role' => $si->getRole(),
            'createdAt' => $si->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $si->getUserInvite()->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], $this->spaceInviteRepository->findActiveBySpace($space));

        return $this->json(['invites' => $payload]);
    }

    #[Route(
        '/spaces/{id}/invites/{spaceInviteId}',
        name: 'space_invite_revoke',
        methods: ['DELETE'],
    )]
    public function revoke(
        string $id,
        string $spaceInviteId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$space->isAdmin($user)) {
            if (!$space->hasMember($user)) {
                return $this->json(['error' => 'Space not found.'], 404);
            }
            return $this->json(['error' => 'Only space admins can revoke invites.'], 403);
        }

        $spaceInvite = $this->spaceInviteRepository->find($spaceInviteId);
        if (null === $spaceInvite || !$spaceInvite->getSpace()->getId()?->equals($space->getId())) {
            return $this->json(['error' => 'Invite not found.'], 404);
        }

        $userInvite = $spaceInvite->getUserInvite();
        $this->em->remove($spaceInvite);
        $this->em->flush();

        // If that was the last attachment (group OR space) on the
        // invite, drop the parent so the token can't be redeemed for
        // nothing.
        $this->em->refresh($userInvite);
        if (
            0 === $userInvite->getGroupInvites()->count()
            && 0 === $userInvite->getSpaceInvites()->count()
        ) {
            $this->em->remove($userInvite);
            $this->em->flush();
        }

        return new JsonResponse(null, 204);
    }
}
