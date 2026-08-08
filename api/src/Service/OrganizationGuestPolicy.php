<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enforces the guest invariant *retroactively* (#billing Phase 1c).
 *
 * {@see SpaceMemberAdder} keeps a guest from ever being *granted* more than the
 * restricted guest space-role. That covers the forward direction, but not the
 * other one: demoting an existing member to Guest left every space role they
 * already held untouched, so an org admin could downgrade someone to a free
 * seat and they'd quietly keep administering spaces. The account said guest;
 * the spaces said admin; the spaces won.
 *
 * This closes that by walking the org's spaces at the moment of demotion and
 * capping whatever the user already has. It's deliberately a separate pass
 * rather than a listener on SpaceMembership: the trigger is an *org* role
 * change, and a membership-level hook would have to re-derive that on every
 * write to catch a case that happens rarely.
 */
final class OrganizationGuestPolicy
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Cap every space membership this user holds in the organization down to
     * the guest role. Returns the number of memberships changed. Does not
     * flush — the caller owns the transaction.
     */
    public function applyGuestCap(Organization $organization, User $user): int
    {
        $changed = 0;

        foreach ($this->spacesOf($organization) as $space) {
            $membership = $this->membershipFor($space, $user);
            if (null === $membership) {
                continue;
            }

            $touched = false;

            if (Space::ROLE_ADMIN === $membership->getRole()) {
                // A space must keep an admin, so a guest who is the *only*
                // admin is left alone and reported rather than silently
                // demoted — stranding a space with nobody who can manage it
                // would be a worse outcome than a brief invariant gap, and the
                // caller surfaces it as a 409 the admin can act on.
                if ($this->adminCount($space) > 1) {
                    $membership->setRole(Space::ROLE_MEMBER);
                    $touched = true;
                }
            }

            $guestRole = $this->guestRoleOf($space);
            $currentKeys = [];
            foreach ($membership->getRoles() as $role) {
                $currentKeys[] = $role->getBuiltinKey();
            }
            $alreadyOnlyGuest = [SpaceRole::BUILTIN_GUEST] === $currentKeys;

            if (null !== $guestRole && !$alreadyOnlyGuest) {
                $membership->clearRoles();
                $membership->addRole($guestRole);
                $touched = true;
            }

            if ($touched) {
                ++$changed;
            }
        }

        return $changed;
    }

    /**
     * Spaces in the org where the user is the sole admin, so a demotion would
     * leave them unmanageable. Callers check this *before* demoting.
     *
     * @return list<Space>
     */
    public function spacesSolelyAdminedBy(Organization $organization, User $user): array
    {
        $blocked = [];
        foreach ($this->spacesOf($organization) as $space) {
            $membership = $this->membershipFor($space, $user);
            if (null === $membership || Space::ROLE_ADMIN !== $membership->getRole()) {
                continue;
            }
            if ($this->adminCount($space) <= 1) {
                $blocked[] = $space;
            }
        }

        return $blocked;
    }

    /**
     * @return list<Space>
     */
    private function spacesOf(Organization $organization): array
    {
        /** @var list<Space> $spaces */
        $spaces = $this->em->getRepository(Space::class)->findBy(['organization' => $organization]);

        return $spaces;
    }

    private function membershipFor(Space $space, User $user): ?SpaceMembership
    {
        $userId = $user->getId();
        if (null === $userId) {
            return null;
        }
        foreach ($space->getUserMemberships() as $membership) {
            if (true === $membership->getUser()?->getId()?->equals($userId)) {
                return $membership;
            }
        }

        return null;
    }

    private function guestRoleOf(Space $space): ?SpaceRole
    {
        return $this->em->getRepository(SpaceRole::class)
            ->findOneBy(['space' => $space, 'builtinKey' => SpaceRole::BUILTIN_GUEST]);
    }

    private function adminCount(Space $space): int
    {
        $count = 0;
        foreach ($space->getUserMemberships() as $membership) {
            if (Space::ROLE_ADMIN === $membership->getRole()) {
                ++$count;
            }
        }

        return $count;
    }
}
