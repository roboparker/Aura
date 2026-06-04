<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Space;
use App\Entity\SpaceInvite;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Entity\UserInvite;
use App\Repository\SpaceInviteRepository;
use App\Repository\UserInviteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Per-email "add or invite" logic for a Space. Branches on whether the
 * email matches an existing user — direct {@see SpaceMembership} if so,
 * upserted {@see UserInvite} + {@see SpaceInvite} otherwise. Extracted
 * from {@see \App\Controller\SpaceMemberController} so the same flow
 * can drive the create-with-invites path on POST /spaces without
 * duplicating the branching, the token rotation, or the parent-invite
 * dedupe.
 *
 * The service does NOT flush and does NOT send the signup email. The
 * caller decides when to flush (so batches can land in a single
 * transaction) and when to dispatch the email (so the mailer sees the
 * post-flush state of the invite's collections).
 */
final class SpaceMemberAdder
{
    public const INVITE_TTL_DAYS = 14;

    public function __construct(
        private EntityManagerInterface $em,
        private UserInviteRepository $userInviteRepository,
        private SpaceInviteRepository $spaceInviteRepository,
        private InviteMailer $inviteMailer,
    ) {
    }

    /**
     * @return array{status: 'already_member', email: string, user: User}
     *     |array{status: 'added', email: string, user: User}
     *     |array{status: 'invited', email: string, invite: UserInvite, plainToken: string}
     */
    public function add(
        Space $space,
        string $email,
        User $invitedBy,
        string $role = Space::ROLE_MEMBER,
    ): array {
        $candidate = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (null !== $candidate) {
            foreach ($space->getUserMemberships() as $existing) {
                if (true === $existing->getUser()?->getId()?->equals($candidate->getId())) {
                    return [
                        'status' => 'already_member',
                        'email' => $email,
                        'user' => $candidate,
                    ];
                }
            }

            $membership = (new SpaceMembership())
                ->setUser($candidate)
                ->setRole($role);
            $space->addUserMembership($membership);
            $this->em->persist($membership);

            return [
                'status' => 'added',
                'email' => $email,
                'user' => $candidate,
            ];
        }

        return $this->upsertInvite($space, $email, $invitedBy, $role);
    }

    /**
     * @return array{status: 'invited', email: string, invite: UserInvite, plainToken: string}
     */
    private function upsertInvite(
        Space $space,
        string $email,
        User $invitedBy,
        string $role = Space::ROLE_MEMBER,
    ): array {
        $invite = $this->userInviteRepository->findByEmail($email);

        // Re-treat a previously-accepted UserInvite row as a fresh
        // invite — its `acceptedAt` means a signup already used it
        // once, and a stale row would mismatch our brand-new token.
        if (null !== $invite && null !== $invite->getAcceptedAt()) {
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

        $existing = null !== $invite->getId()
            ? $this->spaceInviteRepository->findByInviteAndSpace($invite, $space)
            : null;
        if (null === $existing) {
            // Constructor wires the SpaceInvite into the parent's
            // collection so cascade=persist picks it up on flush.
            new SpaceInvite($invite, $space, $invitedBy, $role);
        } else {
            // Re-inviting an already-attached email can change the role.
            $existing->setRole($role);
        }

        return [
            'status' => 'invited',
            'email' => $email,
            'invite' => $invite,
            'plainToken' => $plainToken,
        ];
    }

    /**
     * Convenience for callers — dispatches the signup-link email with
     * the canonical TTL. Call after flushing so the mailer's iteration
     * over `$invite->getSpaceInvites()` sees the persisted attachments.
     */
    public function sendInviteEmail(UserInvite $invite, string $plainToken): void
    {
        $this->inviteMailer->sendSignupLink($invite, $plainToken, self::INVITE_TTL_DAYS);
    }
}
