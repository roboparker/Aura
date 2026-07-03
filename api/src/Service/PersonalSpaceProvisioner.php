<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provisions a user's non-deletable personal "Private" space (#181): the space
 * row, the owner's admin membership, and the seeded built-in roles, flushed
 * together. Shared by the password signup processor and social sign-up
 * ({@see \App\Controller\SsoController}) so both paths create an identical,
 * valid account.
 */
final class PersonalSpaceProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpaceRoleSeeder $roleSeeder,
    ) {
    }

    public function provision(User $user): void
    {
        $space = (new Space())
            ->setName(Space::PERSONAL_SPACE_NAME)
            ->setIsPersonal(true)
            ->setVisibility(Space::VISIBILITY_PRIVATE)
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
