<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Repository\GroupInviteRepository;
use App\Repository\UserInviteRepository;
use App\Service\InviteMailer;
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
    private const INVITE_TTL_DAYS = 14;

    public function __construct(
        private EntityManagerInterface $em,
        private UserInviteRepository $userInviteRepository,
        private GroupInviteRepository $groupInviteRepository,
        private InviteMailer $inviteMailer,
    ) {
    }

    #[Route('/invites/{token}', name: 'user_invite_lookup', methods: ['GET'])]
    public function lookup(string $token): JsonResponse
    {
        $invite = $this->userInviteRepository->findByTokenHash(hash('sha256', $token));
        if (null === $invite) {
            return $this->json(['error' => 'Invitation is invalid or expired.'], 404);
        }

        // Branch the response on the invite's life-cycle so the PWA can
        // render dedicated screens for each (expired vs. already used vs.
        // ready to redeem) instead of bucketing every failure mode into
        // "invalid or expired". Inviter chip + email are returned in all
        // three states because the expired / already-used screens still
        // want to show "this was for X, sent by Y".
        $primaryInviter = self::primaryInviter($invite);
        $base = [
            'email' => $invite->getEmail(),
            'expiresAt' => $invite->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'inviter' => $primaryInviter,
        ];

        if (null !== $invite->getAcceptedAt()) {
            return $this->json([
                ...$base,
                'status' => 'accepted',
                'acceptedAt' => $invite->getAcceptedAt()->format(\DateTimeInterface::ATOM),
            ]);
        }

        if (!$invite->isPending()) {
            return $this->json([
                ...$base,
                'status' => 'expired',
            ]);
        }

        $groups = [];
        foreach ($invite->getGroupInvites() as $groupInvite) {
            $group = $groupInvite->getGroup();
            $groups[] = [
                'id' => (string) $group->getId(),
                'title' => $group->getTitle(),
                'memberCount' => $group->getMemberships()->count(),
                'invitedBy' => self::inviterChip($groupInvite->getInvitedBy()),
            ];
        }
        $spaces = [];
        foreach ($invite->getSpaceInvites() as $spaceInvite) {
            $space = $spaceInvite->getSpace();
            $spaces[] = [
                'id' => (string) $space->getId(),
                'name' => $space->getName(),
                'role' => $spaceInvite->getRole(),
                'invitedBy' => self::inviterChip($spaceInvite->getInvitedBy()),
            ];
        }

        return $this->json([
            ...$base,
            'status' => 'pending',
            'groups' => $groups,
            'spaces' => $spaces,
        ]);
    }

    /**
     * Picks the inviter to show at the top of the signup card. Both group
     * and space invites carry their own `invitedBy`; in practice all
     * attachments on a UserInvite share an inviter, but if they don't we
     * still want a stable "primary" for the headline. First non-null
     * inviter wins.
     *
     * @return array{name: string, email: string, initials: string, color: string}|null
     */
    private static function primaryInviter(\App\Entity\UserInvite $invite): ?array
    {
        foreach ($invite->getSpaceInvites() as $spaceInvite) {
            return self::inviterChip($spaceInvite->getInvitedBy());
        }
        foreach ($invite->getGroupInvites() as $groupInvite) {
            return self::inviterChip($groupInvite->getInvitedBy());
        }
        return null;
    }

    /**
     * @return array{name: string, email: string, initials: string, color: string}
     */
    private static function inviterChip(User $user): array
    {
        $given = trim($user->getGivenName());
        $family = trim($user->getFamilyName());
        $displayName = trim(sprintf('%s %s', $given, $family));
        $initialsRaw = ($given[0] ?? '') . ($family[0] ?? '');
        $initials = '' !== $initialsRaw
            ? strtoupper($initialsRaw)
            : strtoupper(substr($user->getEmail(), 0, 1));
        return [
            'name' => '' !== $displayName ? $displayName : $user->getEmail(),
            'email' => $user->getEmail(),
            'initials' => $initials,
            'color' => $user->getPersonalizedColor(),
        ];
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
            if (!$group->hasMember($user)) {
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
            if (!$group->hasMember($user)) {
                return $this->json(['error' => 'Group not found.'], 404);
            }
            return $this->json(['error' => 'Only the group owner can revoke invites.'], 403);
        }

        $groupInvite = $this->groupInviteRepository->find($groupInviteId);
        if (null === $groupInvite || true !== $groupInvite->getGroup()->getId()?->equals($group->getId())) {
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

    /**
     * Owner-only: re-send the signup email for a pending GroupInvite. The
     * parent UserInvite's token is rotated and its expiry refreshed (so
     * the most recent email is always the authoritative link) and the
     * mail is dispatched with the full set of groups/spaces attached to
     * that address — same shape as the original send.
     */
    #[Route(
        '/groups/{id}/invites/{groupInviteId}/resend',
        name: 'user_invite_resend',
        methods: ['POST'],
    )]
    public function resend(
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
            if (!$group->hasMember($user)) {
                return $this->json(['error' => 'Group not found.'], 404);
            }
            return $this->json(['error' => 'Only the group owner can resend invites.'], 403);
        }

        $groupInvite = $this->groupInviteRepository->find($groupInviteId);
        if (null === $groupInvite || true !== $groupInvite->getGroup()->getId()?->equals($group->getId())) {
            return $this->json(['error' => 'Invite not found.'], 404);
        }

        $invite = $groupInvite->getUserInvite();
        $plainToken = bin2hex(random_bytes(32));
        $invite->setTokenHash(hash('sha256', $plainToken));
        $invite->setExpiresAt(new \DateTimeImmutable(sprintf('+%d days', self::INVITE_TTL_DAYS)));
        $this->em->flush();

        $this->inviteMailer->sendSignupLink($invite, $plainToken, self::INVITE_TTL_DAYS);

        return $this->json([
            'status' => 'resent',
            'email' => $invite->getEmail(),
            'expiresAt' => $invite->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], 200);
    }

    private function isOwnerOrAdmin(UserGroup $group, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $group->getOwner()?->getId()?->equals($user->getId()) ?? false;
    }
}
