<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Parses `@mention` tokens out of a {@see Comment} body and creates
 * one `mention` Notification per resolved recipient. The recipient
 * set is derived from the comment's parent:
 *
 *  - task comments: task owner + project space members.
 *  - page comments: page's space members.
 *  - discussion comments: discussion's space members.
 *
 * Unknown handles are ignored silently — the spec treats them as
 * plain text rather than 4xx-ing so users can write `@TODO` or
 * `@anywhere` without provoking validation noise.
 *
 * Idempotent on edit: the `(recipient, comment)` unique index plus
 * the pre-flight existsForCommentRecipient check make sure adding a
 * new mention to an existing comment notifies only the new recipient,
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
     * found in the body, preserving first-seen order.
     *
     * @return string[]
     */
    public function extractMentions(string $body): array
    {
        if (1 > preg_match_all(self::MENTION_PATTERN, $body, $matches)) {
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
     * Creates Notification rows for each mention in the comment's
     * body that resolves to an authorized recipient. Returns the
     * count of rows created — 0 when every mention was unknown, the
     * comment author themselves (no self-pings), or already notified.
     */
    public function dispatchMentions(Comment $comment): int
    {
        $body = $comment->getBody();
        $tokens = $this->extractMentions($body);
        if ([] === $tokens) {
            return 0;
        }

        $author = $comment->getAuthor();
        if (null === $author) {
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
            if (true === $author->getId()?->equals($user->getId())) {
                continue;
            }
            if ($this->alreadyNotified($user, $comment)) {
                continue;
            }

            $notification = new Notification();
            $notification->setRecipient($user);
            $notification->setComment($comment);
            $notification->setType(Notification::TYPE_MENTION);

            // Carry the parent task on `Notification.task` when the
            // comment is task-scoped so the existing deep-link path
            // on the bell keeps working. Page-scoped notifications
            // leave `task` null and rely on `comment` for the link.
            $task = $comment->getTask();
            if (null !== $task) {
                $notification->setTask($task);
            }

            $notification->setTitle($this->renderTitle($author, $comment));
            $notification->setBody($this->snippet($body));
            $this->em->persist($notification);

            try {
                $this->em->flush();
                ++$created;
            } catch (UniqueConstraintViolationException) {
                // Race with a concurrent edit on the same comment;
                // recover the EM and continue with remaining tokens.
                $this->em->clear();
            }
        }

        return $created;
    }

    /**
     * Recipient set for the comment's parent.
     *
     *  - Task: owner + project space members. Standalone (projectless)
     *    tasks yield just the owner.
     *  - Page: page's space members (effective: direct + via group).
     *
     * @return array<string, User> Keyed by lowercase email-local-part
     *                              for fast token resolution.
     */
    private function collectMentionableUsers(Comment $comment): array
    {
        $bag = [];
        $task = $comment->getTask();
        if (null !== $task) {
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

        $page = $comment->getPage();
        if (null !== $page) {
            $space = $page->getSpace();
            if (null !== $space) {
                foreach ($space->getEffectiveUsers() as $member) {
                    $bag[$this->localPart($member)] = $member;
                }
            }
            return $bag;
        }

        $discussion = $comment->getDiscussion();
        if (null !== $discussion) {
            $space = $discussion->getSpace();
            if (null !== $space) {
                foreach ($space->getEffectiveUsers() as $member) {
                    $bag[$this->localPart($member)] = $member;
                }
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

    private function renderTitle(User $author, Comment $comment): string
    {
        $name = $this->displayName($author);
        $task = $comment->getTask();
        if (null !== $task) {
            return sprintf('%s mentioned you on "%s"', $name, $task->getTitle());
        }
        $page = $comment->getPage();
        if (null !== $page) {
            return sprintf('%s mentioned you on "%s"', $name, $page->getTitle());
        }
        $discussion = $comment->getDiscussion();
        if (null !== $discussion) {
            return sprintf('%s mentioned you on "%s"', $name, $discussion->getTitle());
        }
        return sprintf('%s mentioned you', $name);
    }

    private function displayName(User $user): string
    {
        $name = trim($user->getGivenName() . ' ' . $user->getFamilyName());
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
