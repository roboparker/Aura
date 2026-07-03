<?php

namespace App\Service;

use App\Entity\CalendarFeedToken;
use App\Entity\User;
use App\Repository\CalendarFeedTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the lifecycle of a user's calendar-feed token: reveal (metadata),
 * (re)generate, revoke, and resolve-by-plaintext. The plaintext secret is
 * generated here and returned exactly once; only its sha256 hash is persisted,
 * so nothing downstream — not the DB, not the API — can reproduce a live feed
 * URL after the fact (mirrors the password-reset / export-token model).
 */
final class CalendarFeedTokenManager
{
    /** The route path a plaintext token slots into: /calendar/feed/{token}.ics */
    public const FEED_PATH_PREFIX = '/calendar/feed/';
    public const FEED_PATH_SUFFIX = '.ics';

    public function __construct(
        private readonly CalendarFeedTokenRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function findForUser(User $user): ?CalendarFeedToken
    {
        return $this->repository->findForUser($user);
    }

    /**
     * Resolve a plaintext token to its (owning) record, or null when unknown.
     * Constant-length guard keeps the hash lookup cheap and avoids feeding an
     * absurd string into the hasher.
     */
    public function resolve(string $plaintext): ?CalendarFeedToken
    {
        if ('' === $plaintext || \strlen($plaintext) > 128) {
            return null;
        }

        return $this->repository->findByTokenHash(hash('sha256', $plaintext));
    }

    /**
     * Create the user's feed token, or rotate it if one already exists, and
     * return the fresh plaintext. The old URL stops resolving immediately.
     */
    public function regenerate(User $user): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plaintext);

        $token = $this->repository->findForUser($user);
        if (null === $token) {
            $token = new CalendarFeedToken($user, $hash);
            $this->em->persist($token);
        } else {
            $token->rotate($hash);
        }
        $this->em->flush();

        return $plaintext;
    }

    /** Delete the user's feed token so the subscribe URL dies. */
    public function revoke(User $user): void
    {
        $token = $this->repository->findForUser($user);
        if (null !== $token) {
            $this->em->remove($token);
            $this->em->flush();
        }
    }

    /**
     * The absolute subscribe URL for a freshly-minted plaintext token, built
     * off the app's own origin (the feed is served from the same host).
     */
    public function buildFeedUrl(string $baseUrl, string $plaintext): string
    {
        return rtrim($baseUrl, '/') . self::FEED_PATH_PREFIX . $plaintext . self::FEED_PATH_SUFFIX;
    }
}
