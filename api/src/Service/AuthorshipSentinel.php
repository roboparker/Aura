<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Board;
use App\Entity\Comment;
use App\Entity\Feedback;
use App\Entity\MediaObject;
use App\Entity\Page;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The single authority on **who owns content whose author has been deleted**.
 *
 * Every authorship FK on {@see User} is non-null and `onDelete: CASCADE`, so
 * removing an author without first moving their content erases it. The fix is
 * to reassign to a reserved placeholder account, which
 * {@see AccountDeletionService} has always done for people.
 *
 * Two things are centralised here, and each one exists because of a specific
 * way the alternative goes wrong.
 *
 * **The list of authorship FKs.** It used to live inline in the account
 * deletion path. The moment a second deletion path existed (agents), that list
 * would have been copy-pasted — and the copies drift silently the first time
 * somebody adds a seventh authored entity, because nothing fails: content just
 * quietly disappears on one path and not the other.
 *
 * **Which placeholder to use.** There are two, and picking the wrong one is a
 * correctness bug rather than a cosmetic one — see {@see sentinelFor()}.
 */
final class AuthorshipSentinel
{
    /** A person who no longer has an account. */
    public const HUMAN_EMAIL = 'former-member@system.invalid';

    /** An AI agent that has been removed (#827). */
    public const AGENT_EMAIL = 'removed-agent@system.invalid';

    /** Neither placeholder may itself be deleted. */
    public const EMAILS = [self::HUMAN_EMAIL, self::AGENT_EMAIL];

    /**
     * Every non-null authorship FK pointing at User, as `[entity, field]`.
     *
     * Adding an entity that records an author means adding it here — this is
     * the list both deletion paths walk, and the only one.
     *
     * @var list<array{0: class-string, 1: string}>
     */
    private const AUTHORSHIP = [
        [Task::class, 'owner'],
        [Board::class, 'owner'],
        [Page::class, 'createdBy'],
        [Comment::class, 'author'],
        [Feedback::class, 'owner'],
        [MediaObject::class, 'owner'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * The placeholder that should inherit this subject's content.
     *
     * An agent's output must **not** land on the "Former member" account.
     * That name says *a person who used to work here*, and a reader coming
     * across a comment under it would reasonably believe a colleague wrote it.
     * Misattributing machine-written content to a human is a worse outcome than
     * either deleting it or leaving it unattributed, so agents get their own
     * placeholder — itself flagged `isAgent`, so every surface that already
     * hides or labels agents keeps behaving correctly on the orphaned content.
     */
    public function sentinelFor(User $subject): User
    {
        return $subject->isAgent()
            ? $this->resolve(self::AGENT_EMAIL, 'Removed', 'agent', true)
            : $this->resolve(self::HUMAN_EMAIL, 'Former', 'member', false);
    }

    /** True for either placeholder account. Neither is ever deletable. */
    public function isSentinel(User $user): bool
    {
        return in_array($user->getEmail(), self::EMAILS, true);
    }

    /**
     * Move every piece of content `$from` authored onto `$to`.
     *
     * Bulk UPDATEs rather than loading collections: an account can own a lot of
     * rows, and materialising them to change one FK each would make deleting a
     * busy account a memory problem.
     */
    public function reassign(User $from, User $to): void
    {
        foreach (self::AUTHORSHIP as [$entityClass, $field]) {
            $this->em->createQueryBuilder()
                ->update($entityClass, 'e')
                ->set('e.' . $field, ':to')
                ->where('e.' . $field . ' = :from')
                ->setParameter('to', $to)
                ->setParameter('from', $from)
                ->getQuery()
                ->execute();
        }
    }

    /**
     * How many rows `$user` authored, per entity. Used to tell an operator what
     * a deletion would leave behind before they commit to it.
     *
     * @return array<class-string, int>
     */
    public function authoredCounts(User $user): array
    {
        $counts = [];
        foreach (self::AUTHORSHIP as [$entityClass, $field]) {
            $count = $this->em->createQueryBuilder()
                ->select('COUNT(e)')
                ->from($entityClass, 'e')
                ->where('e.' . $field . ' = :user')
                ->setParameter('user', $user)
                ->getQuery()
                ->getSingleScalarResult();
            $counts[$entityClass] = is_numeric($count) ? (int) $count : 0;
        }

        return $counts;
    }

    /** Find or create a placeholder. Flushed so callers can point FKs at it. */
    private function resolve(string $email, string $givenName, string $familyName, bool $isAgent): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existing) {
            return $existing;
        }

        $sentinel = new User();
        $sentinel->setEmail($email);
        $sentinel->setGivenName($givenName);
        $sentinel->setFamilyName($familyName);
        $sentinel->setPersonalizedColor('#0369a1');
        $sentinel->setIsAgent($isAgent);
        if ($isAgent) {
            // Reads as one label wherever displayName() runs, rather than the
            // given/family pair the NotBlank constraints force onto the row.
            $sentinel->setNickname('Removed agent');
        }
        // Random, never-known password → the account can't be signed into.
        // (An agent placeholder additionally holds no ROLE_USER at all.)
        $sentinel->setPassword($this->hasher->hashPassword($sentinel, bin2hex(random_bytes(24))));
        $this->em->persist($sentinel);
        $this->em->flush();

        return $sentinel;
    }
}
