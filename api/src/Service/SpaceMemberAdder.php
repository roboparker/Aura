<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceInvite;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
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
     * The org role someone auto-joins with when they're added to an org-owned
     * space they're not yet a member of (#billing Phase 1c). The space role
     * they were granted is the only signal available at that moment, so it's
     * what we read: someone trusted to administer a space is a full seat;
     * someone added to collaborate on one space is a free guest until an org
     * admin says otherwise.
     *
     * @var array<string, string>
     */
    public const ORG_ROLE_FOR_SPACE_ROLE = [
        Space::ROLE_ADMIN => Organization::ROLE_MEMBER,
        Space::ROLE_MEMBER => Organization::ROLE_GUEST,
    ];

    /**
     * @return array{status: 'already_member', email: string, user: User}
     *     |array{status: 'added', email: string, user: User, orgRole: string|null}
     *     |array{status: 'invited', email: string, invite: UserInvite, plainToken: string}
     */
    public function add(
        Space $space,
        string $email,
        User $invitedBy,
        string $role = Space::ROLE_MEMBER,
        ?string $orgRole = null,
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

            return [
                'status' => 'added',
                'email' => $email,
                'user' => $candidate,
                'orgRole' => $this->attach($space, $candidate, $role, $orgRole),
            ];
        }

        return $this->upsertInvite($space, $email, $invitedBy, $role);
    }

    /**
     * Create the space membership for a known user, joining them to the space's
     * organization first when it has one and they're not in it yet.
     *
     * The order matters: the org join has to land *before* the guest cap is
     * evaluated, or a brand-new guest would be created with a paying seat's
     * space role and the invariant would only bite on their next edit.
     *
     * Shared by the add-by-email endpoint and invite acceptance so a user who
     * arrives through a signup link is subject to exactly the same rules as one
     * added directly — a difference between those two paths would be a way in.
     *
     * Does not flush, and deliberately does not queue the seat sync — the
     * handler re-reads the roster from the database, so dispatching before the
     * caller's flush would push a stale count. Callers pass the returned role to
     * {@see OrganizationSeatSync::scheduleIfBillable()} once they've flushed.
     *
     * Returns the org role the user now holds, or null when the space has no
     * organization.
     */
    public function attach(
        Space $space,
        User $user,
        string $spaceRole = Space::ROLE_MEMBER,
        ?string $orgRole = null,
    ): ?string {
        $resolvedOrgRole = $this->joinOrganization($space, $user, $spaceRole, $orgRole);

        $membership = (new SpaceMembership())
            ->setUser($user)
            ->setRole($this->guestCappedRole($space, $user, $spaceRole));
        // Org guests are confined to the restricted guest space-role — the
        // seat invariant (#billing Phase 1c): a free guest can never be
        // elevated into a paying seat's capabilities.
        $guestRole = $this->guestRoleFor($space, $user);
        if (null !== $guestRole) {
            $membership->addRole($guestRole);
        }
        $space->addUserMembership($membership);
        $this->em->persist($membership);

        return $resolvedOrgRole;
    }

    /**
     * Ensure the user belongs to the space's organization, and return the role
     * they hold there (null when the space is personal-account-owned).
     *
     * An **existing** org member's role is never touched — being added to
     * another space is not a reason to demote an admin to a guest, and a silent
     * downgrade here would be an unattributable permission loss. Only the
     * genuinely-new member gets a role derived from the space role, or the
     * explicit `$requestedOrgRole` an org admin passed.
     */
    private function joinOrganization(
        Space $space,
        User $user,
        string $spaceRole,
        ?string $requestedOrgRole,
    ): ?string {
        $organization = $space->getOrganization();
        if (null === $organization) {
            return null;
        }

        $existing = $organization->roleFor($user);
        if (null !== $existing) {
            return $existing;
        }

        $role = null !== $requestedOrgRole && in_array($requestedOrgRole, Organization::ALLOWED_ROLES, true)
            ? $requestedOrgRole
            : (self::ORG_ROLE_FOR_SPACE_ROLE[$spaceRole] ?? Organization::ROLE_GUEST);

        $organization->addMember($user, $role);

        return $role;
    }

    /**
     * True when the space is org-owned and the user is a Guest of that org, so
     * they may only ever hold the restricted guest space-role.
     */
    public function isOrgGuest(Space $space, User $user): bool
    {
        $org = $space->getOrganization();

        return null !== $org && Organization::ROLE_GUEST === $org->roleFor($user);
    }

    /** Downgrade an org guest's requested space role — they can't be admins. */
    private function guestCappedRole(Space $space, User $user, string $requested): string
    {
        return $this->isOrgGuest($space, $user) ? Space::ROLE_MEMBER : $requested;
    }

    /** The space's built-in guest role, when the user is an org guest. */
    private function guestRoleFor(Space $space, User $user): ?SpaceRole
    {
        if (!$this->isOrgGuest($space, $user)) {
            return null;
        }

        return $this->em->getRepository(SpaceRole::class)
            ->findOneBy(['space' => $space, 'builtinKey' => SpaceRole::BUILTIN_GUEST]);
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
