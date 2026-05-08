<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Parses `@mention` tokens out of a comment body and creates one
 * `task_mention` Notification per resolved recipient. Tokens are
 * resolved against the comment's task: project members + task owner.
 * Mentions of users outside that set are ignored silently — the spec
 * (#106) treats unknown handles as plain text rather than an error so
 * users can write `@TODO` or `@anywhere` without provoking a 4xx.
 *
 * Idempotent on edit: the (recipient, comment) unique index plus the
 * pre-flight existsForCommentRecipient check make sure adding a new
 * mention to an existing comment notifies only the new recipient,
 * never re-pings the previously mentioned ones.
 */
final class CommentMentionService
{
    /**
     * Matches `@token` where token is a non-whitespace run that looks
     * like an email local-part (ASCII alphanumerics, dot, underscore,
     * dash, plus). Bounded by start-of-string or non-word so we don't
     * mistake `email@host` inside prose for a mention.
     */
    private const MENTION_PATTERN = '/(?:^|\s)@([A-Za-z0-9._+-]+)/';

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Returns the deduplicated list of @mention tokens (without the @)
     * found in the body, preserving first-seen order. Used by callers
     * that want to render or display the list; the create path below
     * uses the same parser internally.
     *
     * @return string[]
     */
    public function extractMentions(string $body): array
    {
        if (!preg_match_all(self::MENTION_PATTERN, $body, $matches)) {
            return [];
        }
        $seen = [];
        foreach ($matches[1] as $token) {
            $key = strtolower($token);
            if (!isset($seen[$key])) {
                $seen[$key] = $token;
            }
        }
        return array_values($seen);
    }

    /**
     * Creates Notification rows for each mention in the comment's body
     * that resolves to an authorized recipient (project member or task
     * owner). Returns the count of rows created — 0 when every mention
     * was either unknown, the comment author themselves (no self-pings),
     * or already notified.
     */
    public function dispatchMentions(Comment $comment): int
    {
        $body = $comment->getBody();
        $tokens = $this->extractMentions($body);
        if ([] === $tokens) {
            return 0;
        }

        $task = $comment->getTask();
        $author = $comment->getAuthor();
        if (null === $task || null === $author) {
            return 0;
        }

        $candidates = $this->collectMentionableUsers($comment);
        if ([] === $candidates) {
            return 0;
        }

        $created = 0;
        foreach ($tokens as $token) {
            $user = $this->resolveToken($token, $candidates);
            if (null === $user) {
                continue;
            }
            // Suppress self-mentions — pinging yourself is just noise.
            if ($author->getId()?->equals($user->getId())) {
                continue;
            }
            if ($this->alreadyNotified($user, $comment)) {
                continue;
            }

            $notification = new Notification();
            $notification->setRecipient($user);
            $notification->setTask($task);
            $notification->setComment($comment);
            $notification->setType(Notification::TYPE_TASK_MENTION);
            $notification->setTitle(sprintf(
                '%s mentioned you on "%s"',
                $this->displayName($author),
                $task->getTitle(),
            ));
            $notification->setBody($this->snippet($body));
            $this->em->persist($notification);

            try {
                $this->em->flush();
                ++$created;
            } catch (UniqueConstraintViolationException) {
                // Race with a concurrent edit on the same comment —
                // the row landed via the other path. Recover the EM
                // and continue with the remaining tokens.
                $this->em->clear();
            }
        }

        return $created;
    }

    /**
     * Space members of the parent project + task owner (#185). Personal
     * tasks (no project) yield just the owner — useful for self-mention
     * suppression rather than notification, but the contract stays
     * consistent.
     *
     * @return User[] Keyed by lowercase email-local-part for fast resolution.
     */
    private function collectMentionableUsers(Comment $comment): array
    {
        $task = $comment->getTask();
        if (null === $task) {
            return [];
        }
        $bag = [];
        $owner = $task->getOwner();
        if (null !== $owner) {
            $bag[$this->localPart($owner)] = $owner;
        }
        $project = $task->getProject();
        if (null !== $project) {
            foreach ($project->getEffectiveMembers() as $member) {
                $bag[$this->localPart($member)] = $member;
            }
        }
        return $bag;
    }

    /**
     * @param array<string, User> $candidates
     */
    private function resolveToken(string $token, array $candidates): ?User
    {
        return $candidates[strtolower($token)] ?? null;
    }

    private function localPart(User $user): string
    {
        $email = strtolower($user->getEmail());
        $at = strpos($email, '@');
        return false === $at ? $email : substr($email, 0, $at);
    }

    private function alreadyNotified(User $recipient, Comment $comment): bool
    {
        return null !== $this->em->getRepository(Notification::class)->findOneBy([
            'recipient' => $recipient,
            'comment' => $comment,
        ]);
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->getGivenName() ?? '') . ' ' . ($user->getFamilyName() ?? ''));
        return '' !== $name ? $name : $user->getEmail();
    }

    private function snippet(string $body): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        return mb_strlen($clean) > 200
            ? mb_substr($clean, 0, 197) . '…'
            : $clean;
    }
}
