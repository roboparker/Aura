<?php

namespace App\Tests\Api;

use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared test helpers for the space-based access model (#185).
 *
 * Tests that previously called `$project->addMember($user)` to widen
 * project access now go through space membership. The personal space
 * created on User signup is the default home for projects, so the
 * common pattern "Alice has a project shared with Bob" maps to
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
     * Add a user to the project's space (#185 replacement for the old
     * `$project->addMember($user)` test helper). If the project hasn't
     * been persisted yet — `Project.space` is set by
     * `ProjectSpaceDefaultListener` at PrePersist — we flush it first
     * so the space exists before we reference it. This lets test
     * helpers stay terse: `new Project; setOwner; addProjectMember`
     * without a manual persist line in between.
     */
    protected function addProjectMember(
        Project $project,
        User $user,
        string $role = Space::ROLE_MEMBER,
    ): SpaceMembership {
        $space = $project->getSpace();
        if (null === $space) {
            $em = $this->entityManagerForFixture();
            $em->persist($project);
            $em->flush();
            $space = $project->getSpace();
        }
        if (null === $space) {
            throw new \RuntimeException(
                'Project must have a space after persist — ProjectSpaceDefaultListener should have fired.',
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
        if (!property_exists($this, 'entityManager') || !$this->entityManager instanceof EntityManagerInterface) {
            throw new \LogicException(
                'SpaceMembershipFixture requires the test class to expose an `$entityManager` property.',
            );
        }
        return $this->entityManager;
    }
}
