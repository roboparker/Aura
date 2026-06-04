<?php

namespace App\Controller;

use App\Entity\GroupInvite;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserInvite;
use App\Repository\GroupInviteRepository;
use App\Repository\UserInviteRepository;
use App\Service\InviteMailer;
use App\Service\SensitiveActionVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Add-by-email endpoint for group members. Branches on whether the email
 * matches an existing user:
 *   - existing user → add directly to the group's member set (status: "added")
 *   - unknown email → upsert a UserInvite (one per email), attach a
 *     GroupInvite for this group, rotate the token, and email a signup link
 *     covering every group the address has been invited to. Status: "invited".
 *
 * When the invitee signs up via that link UserPasswordHasherProcessor adds
 * them to all attached groups.
 */
class UserGroupMemberController extends AbstractController
{
    private const INVITE_TTL_DAYS = 14;

    public function __construct(
        private EntityManagerInterface $em,
        private UserInviteRepository $userInviteRepository,
        private GroupInviteRepository $groupInviteRepository,
        private InviteMailer $inviteMailer,
        private ValidatorInterface $validator,
        private SensitiveActionVerifier $sensitiveActionVerifier,
    ) {
    }

    #[Route('/groups/{id}/members', name: 'user_group_add_member', methods: ['POST'])]
    public function add(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $group = $this->em->getRepository(UserGroup::class)->find($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found.'], 404);
        }

        $isOwner = $group->getOwner()?->getId()?->equals($user->getId()) ?? false;
        if (!$isOwner && !$this->isGranted('ROLE_ADMIN')) {
            // Non-owner members shouldn't be able to confirm the group's
            // existence beyond what they see in their listings; 404 matches
            // the access-extension treatment for non-members.
            if (!$group->hasMember($user)) {
                return $this->json(['error' => 'Group not found.'], 404);
            }
            return $this->json(['error' => 'Only the group owner can add members.'], 403);
        }

        $payload = $request->toArray();
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        if ('' === $email) {
            return $this->json(['error' => 'Email is required.'], 400);
        }

        $emailViolations = $this->validator->validate($email, [
            new Assert\Email(),
            new Assert\Length(max: 180),
        ]);
        if (count($emailViolations) > 0) {
            return $this->json(['error' => 'Please provide a valid email address.'], 422);
        }

        $candidate = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $candidate) {
            if ($group->hasMember($candidate)) {
                return $this->json(['error' => 'That user is already a member.'], 409);
            }

            $group->addMember($candidate);
            $this->em->flush();

            return $this->json([
                'status' => 'added',
                'id' => (string) $candidate->getId(),
                '@id' => '/users/' . $candidate->getId(),
                'email' => $candidate->getEmail(),
            ], 200);
        }

        return $this->upsertInvite($group, $email, $user);
    }

    /**
     * Owner-only: remove a member from the group. The owner cannot remove
     * themselves this way — they must transfer ownership or leave (which
     * is blocked for owners) / delete the group instead.
     */
    #[Route('/groups/{id}/members/{userId}', name: 'user_group_remove_member', methods: ['DELETE'])]
    public function remove(string $id, string $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $group = $this->em->getRepository(UserGroup::class)->find($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found.'], 404);
        }

        $isOwner = $group->getOwner()?->getId()?->equals($user->getId()) ?? false;
        if (!$isOwner && !$this->isGranted('ROLE_ADMIN')) {
            if (!$group->hasMember($user)) {
                return $this->json(['error' => 'Group not found.'], 404);
            }
            return $this->json(['error' => 'Only the group owner can remove members.'], 403);
        }

        $target = $this->em->getRepository(User::class)->find($userId);
        if (null === $target || !$group->hasMember($target)) {
            return $this->json(['error' => 'That user is not a member of this group.'], 404);
        }

        if (true === $group->getOwner()?->getId()?->equals($target->getId())) {
            return $this->json(
                ['error' => 'The owner cannot be removed. Transfer ownership first.'],
                409,
            );
        }

        $group->removeMember($target);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    /**
     * Member self-removal. The owner can't leave (they'd orphan the
     * group) — they must transfer ownership or delete it instead.
     */
    #[Route('/groups/{id}/leave', name: 'user_group_leave', methods: ['POST'])]
    public function leave(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $group = $this->em->getRepository(UserGroup::class)->find($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found.'], 404);
        }

        if (!$group->hasMember($user)) {
            // Existence-hiding: a non-member shouldn't be able to probe.
            return $this->json(['error' => 'Group not found.'], 404);
        }

        if (true === $group->getOwner()?->getId()?->equals($user->getId())) {
            return $this->json(
                ['error' => 'The owner cannot leave. Transfer ownership or delete the group.'],
                409,
            );
        }

        $group->removeMember($user);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    /**
     * Owner-only, step-up protected: hand the group to another member. The
     * caller proves identity through {@see SensitiveActionVerifier} (TOTP
     * when 2FA is on, otherwise the current password) so a stolen cookie
     * can't silently reassign the group. The new owner must already be a
     * member; they stay a member and the old owner is demoted to one.
     */
    #[Route('/groups/{id}/transfer', name: 'user_group_transfer', methods: ['POST'])]
    public function transfer(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $group = $this->em->getRepository(UserGroup::class)->find($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found.'], 404);
        }

        $isOwner = $group->getOwner()?->getId()?->equals($user->getId()) ?? false;
        if (!$isOwner && !$this->isGranted('ROLE_ADMIN')) {
            if (!$group->hasMember($user)) {
                return $this->json(['error' => 'Group not found.'], 404);
            }
            return $this->json(['error' => 'Only the group owner can transfer ownership.'], 403);
        }

        $payload = $request->toArray();
        $rawTarget = is_string($payload['newOwner'] ?? null) ? trim($payload['newOwner']) : '';
        if ('' === $rawTarget) {
            return $this->json(['error' => 'A new owner is required.'], 400);
        }
        // Accept either a bare UUID or a /users/{uuid} IRI — basename
        // returns the last path segment for an IRI and the value itself
        // for a bare id.
        $targetId = basename($rawTarget);

        $target = $this->em->getRepository(User::class)->find($targetId);
        if (null === $target || !$group->hasMember($target)) {
            return $this->json(['error' => 'Pick a member of this group as the new owner.'], 422);
        }
        if (true === $group->getOwner()?->getId()?->equals($target->getId())) {
            return $this->json(['error' => 'That user is already the owner.'], 409);
        }

        $failure = $this->sensitiveActionVerifier->verify($user, $payload);
        if (null !== $failure) {
            return $this->json(['error' => $failure[1]], $failure[0]);
        }

        $group->setOwner($target);
        // The target is already a member; the demoted owner stays one too.
        $this->em->flush();

        return $this->json([
            'status' => 'transferred',
            'owner' => '/users/' . $target->getId(),
        ], 200);
    }

    /**
     * Find or create the per-email UserInvite, attach a GroupInvite for
     * this group if one isn't already there, rotate the token, refresh
     * expiry, and resend the email. Token rotation invalidates any
     * older link, so the most recent email is always the authoritative
     * one for that invitee.
     */
    private function upsertInvite(UserGroup $group, string $email, User $invitedBy): JsonResponse
    {
        $invite = $this->userInviteRepository->findByEmail($email);

        if (null !== $invite && null !== $invite->getAcceptedAt()) {
            // The email signed up at some point but we still don't have a
            // matching User row (deleted? race?). Treat as a fresh invite.
            $invite = null;
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d days', self::INVITE_TTL_DAYS));

        if (null === $invite) {
            $invite = new UserInvite($email, $tokenHash, $expiresAt);
            $this->em->persist($invite);
        } else {
            $invite->setTokenHash($tokenHash);
            $invite->setExpiresAt($expiresAt);
        }

        $existingGroupInvite = null !== $invite->getId()
            ? $this->groupInviteRepository->findByInviteAndGroup($invite, $group)
            : null;
        if (null === $existingGroupInvite) {
            // Constructor wires the GroupInvite into the parent's collection
            // so cascade=persist picks it up on flush.
            new GroupInvite($invite, $group, $invitedBy);
        }

        $this->em->flush();

        $this->inviteMailer->sendSignupLink($invite, $plainToken, self::INVITE_TTL_DAYS);

        return $this->json([
            'status' => 'invited',
            'email' => $invite->getEmail(),
            'inviteId' => (string) $invite->getId(),
            'expiresAt' => $invite->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], 200);
    }
}
