<?php

namespace App\Doctrine;

use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Repository\SpaceRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Defaults `Project.space` to the owner's personal space at insert
 * time when the caller doesn't provide one (#181).
 *
 * Production code (`POST /projects`) already sets the space via
 * {@see \App\State\ProjectOwnerProcessor}, so this listener mostly
 * covers direct-EM persistence — tests, future fixtures, CLI
 * commands — without forcing every call site to know about spaces.
 *
 * If the owner happens not to have a personal space yet (only
 * possible when a User is persisted outside `UserPasswordHasherProcessor`,
 * e.g. in a test helper), one is provisioned in the same flush so
 * the unique partial index `uniq_space_personal_per_user` continues
 * to hold.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Project::class)]
final class ProjectSpaceDefaultListener
{
    public function __construct(private SpaceRepository $spaceRepository)
    {
    }

    public function prePersist(Project $project, PrePersistEventArgs $args): void
    {
        if (null !== $project->getSpace()) {
            return;
        }
        $owner = $project->getOwner();
        if (null === $owner) {
            return;
        }

        $space = $this->spaceRepository->findPersonalSpaceFor($owner);
        if (null === $space) {
            // No personal space exists yet — synthesize one so we can
            // still satisfy `space_id NOT NULL`. This is the path
            // tests hit when they instantiate a User directly without
            // routing through UserPasswordHasherProcessor.
            $space = (new Space())
                ->setName(Space::PERSONAL_SPACE_NAME)
                ->setIsPersonal(true)
                ->setCreatedBy($owner);
            $space->addUserMembership(
                (new SpaceMembership())
                    ->setUser($owner)
                    ->setRole(Space::ROLE_ADMIN),
            );
            $args->getObjectManager()->persist($space);
        }
        $project->setSpace($space);
    }
}
