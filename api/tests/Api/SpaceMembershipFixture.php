<?php

namespace App\Tests\Api;

use App\Entity\Board;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared test helpers for the space-based access model (#185).
 *
 * @property EntityManagerInterface $entityManager
 *
 * Tests that previously called `$board->addMember($user)` to widen
 * board access now go through space membership. The personal space
 * created on User signup is the default home for boards, so the
 * common pattern "Alice has a board shared with Bob" maps to
 * "add Bob as a direct member of Alice's personal space" — admittedly
 * odd at the model level, but functionally indistinguishable from a
 * shared space for the purposes of access checks and avoids forcing
 * every test to set up a new Space entity by hand.
 *
 * Consumers must expose `$this->entityManager` (every API test class
 * already does).
 */
trait SpaceMembershipFixture
{
    /**
     * Add a user to the board's space (#185 replacement for the old
     * `$board->addMember($user)` test helper). If the board hasn't
     * been persisted yet — `Board.space` is set by
     * `BoardSpaceDefaultListener` at PrePersist — we flush it first
     * so the space exists before we reference it. This lets test
     * helpers stay terse: `new Board; setOwner; addBoardMember`
     * without a manual persist line in between.
     */
    protected function addBoardMember(
        Board $board,
        User $user,
        string $role = Space::ROLE_MEMBER,
    ): SpaceMembership {
        $space = $board->getSpace();
        if (null === $space) {
            $em = $this->entityManagerForFixture();
            $em->persist($board);
            $em->flush();
            $space = $board->getSpace();
        }
        if (null === $space) {
            throw new \RuntimeException(
                'Board must have a space after persist — BoardSpaceDefaultListener should have fired.',
            );
        }
        return $this->ensureSpaceMembership($space, $user, $role);
    }

    /**
     * Idempotent space membership grant. If the user already holds a
     * direct membership at any role, returns the existing row without
     * upgrading or downgrading. Use a fresh Space + targeted role
     * setup if the test needs admin specifically and the user already
     * has a member row.
     */
    protected function ensureSpaceMembership(
        Space $space,
        User $user,
        string $role = Space::ROLE_MEMBER,
    ): SpaceMembership {
        foreach ($space->getUserMemberships() as $existing) {
            if ($existing->getUser() === $user) {
                return $existing;
            }
        }
        $membership = (new SpaceMembership())
            ->setUser($user)
            ->setRole($role);
        $space->addUserMembership($membership);

        $em = $this->entityManagerForFixture();
        $em->persist($membership);
        $em->flush();
        return $membership;
    }

    private function entityManagerForFixture(): EntityManagerInterface
    {
        // Consumers are required (via the @property declaration above) to
        // expose a typed `$entityManager` property. Phpstan trusts that
        // declaration; if a real consumer forgets to set it the EM call
        // below will fail with a typed-property uninitialized error.
        return $this->entityManager;
    }
}
