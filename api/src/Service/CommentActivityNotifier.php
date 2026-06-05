<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\CommentRepository;

/**
 * Fans a posted comment out to the people who care about the thread,
 * beyond the explicit `@mentions` {@see CommentMentionService} already
 * handled:
 *
 *  - `reply`   → everyone who previously commented in the same thread.
 *  - `comment` → the thread's owner (task owner / discussion author /
 *                page creator) when they aren't already a participant.
 *
 * Runs *after* mentions so the dispatcher's one-row-per-comment dedup
 * enforces the precedence mention > reply > comment: a prior commenter
 * who was also @mentioned keeps the mention; the owner who also replied
 * keeps the reply.
 */
final class CommentActivityNotifier
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private CommentRepository $comments,
    ) {
    }

    public function dispatch(Comment $comment): void
    {
        $author = $comment->getAuthor();
        if (null === $author) {
            return;
        }

        $task = $comment->getTask();
        $parentTitle = $this->parentTitle($comment);
        $name = $this->displayName($author);
        $snippet = $this->snippet($comment->getBody());

        foreach ($this->comments->findPriorAuthors($comment) as $participant) {
            $this->dispatcher->notify(
                recipient: $participant,
                type: Notification::TYPE_REPLY,
                actor: $author,
                title: sprintf('%s replied on "%s"', $name, $parentTitle),
                body: $snippet,
                task: $task,
                comment: $comment,
            );
        }

        $owner = $this->threadOwner($comment);
        if (null !== $owner) {
            $this->dispatcher->notify(
                recipient: $owner,
                type: Notification::TYPE_COMMENT,
                actor: $author,
                title: sprintf('%s commented on "%s"', $name, $parentTitle),
                body: $snippet,
                task: $task,
                comment: $comment,
            );
        }
    }

    private function threadOwner(Comment $comment): ?User
    {
        if (null !== $comment->getTask()) {
            return $comment->getTask()->getOwner();
        }
        if (null !== $comment->getDiscussion()) {
            return $comment->getDiscussion()->getAuthor();
        }
        if (null !== $comment->getPage()) {
            return $comment->getPage()->getCreatedBy();
        }

        return null;
    }

    private function parentTitle(Comment $comment): string
    {
        if (null !== $comment->getTask()) {
            return $comment->getTask()->getTitle();
        }
        if (null !== $comment->getDiscussion()) {
            return $comment->getDiscussion()->getTitle();
        }
        if (null !== $comment->getPage()) {
            return $comment->getPage()->getTitle();
        }

        return 'a thread';
    }

    private function displayName(User $user): string
    {
        $name = trim($user->getGivenName() . ' ' . $user->getFamilyName());

        return '' !== $name ? $name : $user->getEmail();
    }

    private function snippet(string $body): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $body) ?? '');

        return mb_strlen($clean) > 200 ? mb_substr($clean, 0, 197) . '…' : $clean;
    }
}
