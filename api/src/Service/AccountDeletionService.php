<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Discussion;
use App\Entity\Feedback;
use App\Entity\MediaObject;
use App\Entity\Page;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Hard-deletes a user account while preserving the content they authored.
 *
 * Every authorship FK on User is `onDelete: CASCADE` and non-null, so a naive
 * `EntityManager::remove($user)` would erase their tasks, projects, pages,
 * discussions, comments, feedback tickets and media. Instead we reassign those
 * FKs to a reserved
 * "Former member" sentinel inside a transaction *before* removing the user, so
 * the content stays published under an anonymized author. Memberships, API
 * tokens, sessions, notifications, invites and personal tags clear via their
 * existing CASCADE.
 */
final class AccountDeletionService
{
    public const SENTINEL_EMAIL = 'former-member@system.invalid';

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    public function deleteAccount(User $user): void
    {
        if (self::SENTINEL_EMAIL === $user->getEmail()) {
            throw new \RuntimeException('The system sentinel account cannot be deleted.');
        }

        $this->em->wrapInTransaction(function () use ($user): void {
            $userId = (string) $user->getId();
            $sentinel = $this->sentinel();

            // Reassign authored content to the sentinel (bulk UPDATE keeps it
            // cheap and avoids materialising large collections).
            $this->reassign(Task::class, 'owner', $user, $sentinel);
            $this->reassign(Project::class, 'owner', $user, $sentinel);
            $this->reassign(Page::class, 'createdBy', $user, $sentinel);
            $this->reassign(Discussion::class, 'author', $user, $sentinel);
            $this->reassign(Comment::class, 'author', $user, $sentinel);
            $this->reassign(Feedback::class, 'owner', $user, $sentinel);
            $this->reassign(MediaObject::class, 'owner', $user, $sentinel);

            $this->handleSpaces($user, $userId);

            $managed = $this->em->find(User::class, $userId);
            if (null !== $managed) {
                // task_assignee rows clear via CASCADE → the user is dropped
                // from every task's assignee list ("unassigned").
                $this->em->remove($managed);
            }
        });
    }

    private function reassign(string $entityClass, string $field, User $from, User $to): void
    {
        $this->em->createQueryBuilder()
            ->update($entityClass, 'e')
            ->set('e.' . $field, ':to')
            ->where('e.' . $field . ' = :from')
            ->setParameter('to', $to)
            ->setParameter('from', $from)
            ->getQuery()
            ->execute();
    }

    /**
     * Personal space → deleted with the user. Shared space the user solely
     * admins → promote the next member, or delete it if empty.
     */
    private function handleSpaces(User $user, string $userId): void
    {
        $memberships = $this->em->getRepository(SpaceMembership::class)->findBy(['user' => $user]);
        foreach ($memberships as $membership) {
            $space = $membership->getSpace();
            if (null === $space) {
                continue;
            }
            if ($space->getIsPersonal()) {
                $this->em->remove($space);
                continue;
            }
            if (Space::ROLE_ADMIN !== $membership->getRole()) {
                continue;
            }

            $otherAdmins = [];
            $otherMembers = [];
            foreach ($space->getUserMemberships() as $other) {
                if ((string) $other->getUser()?->getId() === $userId) {
                    continue;
                }
                $otherMembers[] = $other;
                if (Space::ROLE_ADMIN === $other->getRole()) {
                    $otherAdmins[] = $other;
                }
            }

            if ([] !== $otherAdmins) {
                continue;
            }
            if ([] !== $otherMembers) {
                $otherMembers[0]->setRole(Space::ROLE_ADMIN);
            } else {
                $this->em->remove($space);
            }
        }
    }

    private function sentinel(): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => self::SENTINEL_EMAIL]);
        if (null !== $existing) {
            return $existing;
        }

        $sentinel = new User();
        $sentinel->setEmail(self::SENTINEL_EMAIL);
        $sentinel->setGivenName('Former');
        $sentinel->setFamilyName('member');
        $sentinel->setPersonalizedColor('#0369a1');
        // Random, never-known password → the account can't be signed into.
        $sentinel->setPassword($this->hasher->hashPassword($sentinel, bin2hex(random_bytes(24))));
        $this->em->persist($sentinel);
        $this->em->flush();

        return $sentinel;
    }
}
