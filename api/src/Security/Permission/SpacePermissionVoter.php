<?php

declare(strict_types=1);

namespace App\Security\Permission;

use App\Entity\Service;
use App\Entity\Project;
use App\Entity\Client;
use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\Discussion;
use App\Entity\Estimate;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Invoice;
use App\Entity\Page;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\TaskRelationship;
use App\Entity\TaskSection;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\UserGroup;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Enforces per-space role permissions (#space-roles, Phase 2) on item / create
 * / update / delete operations. Used from entity `security:` expressions as
 * `is_granted('space.<category>.<action>', object)` — composed AFTER the
 * `ROLE_ADMIN` and owner/author bypasses, so it only narrows what a restricted
 * member may do.
 *
 * @extends Voter<string, object>
 */
final class SpacePermissionVoter extends Voter
{
    public function __construct(private readonly SpacePermissionResolver $resolver)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!str_starts_with($attribute, 'space.')) {
            return false;
        }
        $parts = explode('.', $attribute);

        return 3 === count($parts)
            && SpacePermission::isCategory($parts[1])
            && SpacePermission::isAction($parts[2]);
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        [, $category, $action] = explode('.', $attribute);
        $space = $this->resolveSpace($subject);

        // Admin-reserved categories (api keys, invoicing) must not be swept in by
        // the "member with no roles is unrestricted" back-compat rule — they need
        // an explicit grant (space admin or an assigned role).
        if (in_array($category, SpacePermission::ADMIN_RESERVED, true)) {
            return $this->resolver->canByExplicitGrant($user, $space, $category, $action);
        }

        return $this->resolver->can($user, $space, $category, $action);
    }

    /**
     * The space that gates a subject. `null` means "no space gate" (owner-only
     * or personal content) — the resolver treats that as allowed.
     */
    private function resolveSpace(mixed $subject): ?Space
    {
        return match (true) {
            $subject instanceof Board => $subject->getSpace(),
            $subject instanceof Task => $subject->getBoard()?->getSpace(),
            $subject instanceof Page => $subject->getSpace(),
            $subject instanceof Discussion => $subject->getSpace(),
            $subject instanceof CustomFieldDefinition => $subject->getSpace(),
            $subject instanceof Tag => $subject->getSpace(),
            $subject instanceof UserGroup => $subject->getSpace(),
            $subject instanceof TaskSection => $subject->getBoard()?->getSpace(),
            $subject instanceof TimeEntry => $subject->getSpace(),
            $subject instanceof Client => $subject->getSpace(),
            $subject instanceof Project => $subject->getSpace(),
            $subject instanceof Service => $subject->getProject()?->getSpace(),
            $subject instanceof Invoice => $subject->getSpace(),
            $subject instanceof Estimate => $subject->getSpace(),
            $subject instanceof Expense => $subject->getSpace(),
            $subject instanceof ExpenseCategory => $subject->getSpace(),
            $subject instanceof TaskRelationship => $subject->getSource()?->getBoard()?->getSpace(),
            $subject instanceof Comment => $this->commentSpace($subject),
            default => null,
        };
    }

    private function commentSpace(Comment $comment): ?Space
    {
        $task = $comment->getTask();
        if (null !== $task) {
            return $task->getBoard()?->getSpace();
        }
        $page = $comment->getPage();
        if (null !== $page) {
            return $page->getSpace();
        }
        $discussion = $comment->getDiscussion();
        if (null !== $discussion) {
            return $discussion->getSpace();
        }

        return null;
    }
}
