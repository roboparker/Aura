<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provisions a user's non-deletable personal "Private" space (#181): the
 * personal organization that owns it, the space row, the owner's admin
 * membership, and the seeded built-in roles — all flushed together. Shared by
 * the password signup processor and social sign-up
 * ({@see \App\Controller\SsoController}) so both paths create an identical,
 * valid account.
 *
 * The organization is created here rather than by a separate caller precisely
 * so it lands in the same flush: a user with a space but no account behind it
 * is the state Phase 2 exists to make unrepresentable, and a half-failed signup
 * is the easiest way to produce one.
 */
final class PersonalSpaceProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpaceRoleSeeder $roleSeeder,
        private readonly PersonalOrganizationProvisioner $organizations,
    ) {
    }

    public function provision(User $user): void
    {
        $organization = $this->organizations->provision($user);

        $space = (new Space())
            ->setName(Space::PERSONAL_SPACE_NAME)
            ->setIsPersonal(true)
            ->setVisibility(Space::VISIBILITY_PRIVATE)
            ->setOrganization($organization)
            ->setCreatedBy($user);

        $membership = (new SpaceMembership())
            ->setUser($user)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($membership);

        $this->em->persist($space);
        $this->roleSeeder->seed($space);
        $this->em->flush();
    }
}
