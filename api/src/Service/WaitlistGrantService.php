<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Targeted "grant waitlist access" orchestration, shared by the admin UI
 * endpoint (POST /admin/waitlist/promote) and the CLI (app:waitlist:grant).
 *
 * It sits one level above {@see WaitlistPromoter}: the promoter is the low-level
 * primitive that flips one account's flag and emails them, while this service
 * knows how to *select* the accounts (by id/email, oldest-first, or the whole
 * list) and how to promote a batch while reporting promoted-vs-skipped. Opening
 * signups wholesale stays on WaitlistPromoter::promoteAll() — that path flips
 * every flag in a single flush and isn't a targeted grant.
 */
final class WaitlistGrantService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WaitlistPromoter $promoter,
    ) {
    }

    /**
     * Promote each given user that is still waitlisted (flipping the flag,
     * restoring ROLE_USER, and emailing them via the promoter). A user who
     * already has full access is reported as skipped, never re-promoted or
     * re-emailed — so a stale selection or a double-run is harmless.
     *
     * With $dryRun the same promoted/skipped split is computed but no flag is
     * flipped and no email is sent — a single source of truth for what a real
     * run would do.
     *
     * @param iterable<User> $users
     */
    public function grant(iterable $users, bool $dryRun = false): WaitlistGrantResult
    {
        $promoted = [];
        $skipped = [];
        foreach ($users as $user) {
            if (!$user->isWaitlisted()) {
                $skipped[] = $user;
                continue;
            }
            if (!$dryRun) {
                $this->promoter->promoteOne($user);
            }
            $promoted[] = $user;
        }

        return new WaitlistGrantResult($promoted, $skipped);
    }

    /**
     * Waitlisted users, oldest first. User has no createdOn column, but its id
     * is a time-ordered UUIDv7 (see config/packages/framework.yaml), so ordering
     * by id ASC is chronological — the longest-waiting accounts come first.
     *
     * @return list<User>
     */
    public function findWaitlisted(?int $limit = null): array
    {
        return $this->em->getRepository(User::class)
            ->findBy(['waitlisted' => true], ['id' => 'ASC'], $limit);
    }

    /**
     * Resolve each selector (a UUID or an email) to a user, deduping by id.
     * Selectors that match nothing are collected in `notFound` so the caller
     * can report them however suits its surface.
     *
     * @param list<string> $selectors
     * @return array{users: list<User>, notFound: list<string>}
     */
    public function resolveSelectors(array $selectors): array
    {
        $repo = $this->em->getRepository(User::class);

        $users = [];
        $notFound = [];
        foreach ($selectors as $selector) {
            $user = Uuid::isValid($selector)
                ? $repo->find($selector)
                : $repo->findOneBy(['email' => $selector]);

            if (null === $user) {
                $notFound[] = $selector;
                continue;
            }

            $users[(string) $user->getId()] = $user;
        }

        return ['users' => array_values($users), 'notFound' => $notFound];
    }
}
