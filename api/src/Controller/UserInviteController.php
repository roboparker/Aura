<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Repository\GroupInviteRepository;
use App\Repository\UserInviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Read and revoke pending invites.
 *
 *   GET  /invites/{token}                     — public; returns the invitee
 *       email plus every group attached to the invite, so the signup page
 *       can show context. 404 for unknown / expired / already-accepted.
 *
 *   GET  /groups/{id}/invites                 — owner-only; lists the
 *       active GroupInvites for this group so the owner can see who
 *       hasn't signed up yet.
 *
 *   DELETE /groups/{id}/invites/{groupInviteId} — owner-only; revokes a
 *       single GroupInvite. If it was the last one under its UserInvite,
 *       the parent UserInvite is removed too (no point keeping a token
 *       that buys you access to nothing).
 */
class UserInviteController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserInviteRepository $userInviteRepository,
        private GroupInviteRepository $groupInviteRepository,
    ) {
    }

    #[Route('/invites/{token}', name: 'user_invite_lookup', methods: ['GET'])]
    public function lookup(string $token): JsonResponse
    {
        $invite = $this->userInviteRepository->findByTokenHash(hash('sha256', $token));
        if (null === $invite || !$invite->isPending()) {
            return $this->json(['error' => 'Invitation is invalid or expired.'], 404);
        }

        $groups = [];
        foreach ($invite->getGroupInvites() as $groupInvite) {
            $groups[] = [
                'id' => (string) $groupInvite->getGroup()->getId(),
                'title' => $groupInvite->getGroup()->getTitle(),
                'invitedBy' => $groupInvite->getInvitedBy()->getEmail(),
            ];
        }

        return $this->json([
            'email' => $invite->getEmail(),
            'groups' => $groups,
            'expiresAt' => $invite->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/groups/{id}/invites', name: 'user_invite_list', methods: ['GET'])]
    public function list(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $group = $this->em->getRepository(UserGroup::class)->find($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found.'], 404);
        }

        if (!$this->isOwnerOrAdmin($group, $user)) {
            // Hide existence from non-members; non-owner members get 403.
            if (!$group->getMembers()->contains($user)) {
                return $this->json(['error' => 'Group not found.'], 404);
            }
            return $this->json(['error' => 'Only the group owner can view invites.'], 403);
        }

        $payload = array_map(fn ($gi) => [
            'id' => (string) $gi->getId(),
            'email' => $gi->getUserInvite()->getEmail(),
            'invitedBy' => $gi->getInvitedBy()->getEmail(),
            'createdAt' => $gi->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $gi->getUserInvite()->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], $this->groupInviteRepository->findActiveByGroup($group));

        return $this->json(['invites' => $payload]);
    }

    #[Route(
        '/groups/{id}/invites/{groupInviteId}',
        name: 'user_invite_revoke',
        methods: ['DELETE'],
    )]
    public function revoke(
        string $id,
        string $groupInviteId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $group = $this->em->getRepository(UserGroup::class)->find($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found.'], 404);
        }

        if (!$this->isOwnerOrAdmin($group, $user)) {
            if (!$group->getMembers()->contains($user)) {
                return $this->json(['error' => 'Group not found.'], 404);
            }
            return $this->json(['error' => 'Only the group owner can revoke invites.'], 403);
        }

        $groupInvite = $this->groupInviteRepository->find($groupInviteId);
        if (null === $groupInvite || !$groupInvite->getGroup()->getId()->equals($group->getId())) {
            return $this->json(['error' => 'Invite not found.'], 404);
        }

        $userInvite = $groupInvite->getUserInvite();
        $this->em->remove($groupInvite);
        $this->em->flush();

        // If that was the last group on the invite, drop the parent so
        // the token can't be redeemed for nothing.
        $this->em->refresh($userInvite);
        if (0 === $userInvite->getGroupInvites()->count()) {
            $this->em->remove($userInvite);
            $this->em->flush();
        }

        return new JsonResponse(null, 204);
    }

    private function isOwnerOrAdmin(UserGroup $group, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $group->getOwner()?->getId()?->equals($user->getId()) ?? false;
    }
}
