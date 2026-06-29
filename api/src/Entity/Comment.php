<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\CommentRepository;
use App\State\CommentAuthorProcessor;
use App\State\CommentDeleteProcessor;
use App\State\CommentUpdateProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Unified comment thread (#228). Replaces the per-parent
 * `TaskComment` / `PageComment` split: every comment now lives in one
 * `comment` table with a polymorphic parent — `commentable_type`
 * discriminates between `'task'`, `'page'`, and `'discussion'`, and
 * exactly one of `task` / `page` / `discussion` is set to match. All
 * three are real FKs (rather than a single bare `commentable_id`) so
 * we keep cascade-on-delete from the parent.
 *
 * Comments are flat (no reply tree) and ordered chronologically. The
 * previous task-side reply tree was flattened in the unification
 * migration; the chronological view is the canonical thread surface.
 *
 * Access is parent-aware:
 *   - read: anyone who can read the parent (task accessibility OR
 *     page / discussion space membership).
 *   - post: same set as read. A locked discussion additionally
 *     refuses *new* comments (existing ones stay editable/deletable).
 *   - edit: author only.
 *   - delete: author, OR an admin escalation suited to the parent —
 *     the task's owner can delete on tasks; the space-admin can
 *     delete on pages and discussions.
 *
 * `@mention` tokens in `body` are scanned by
 * {@see App\Service\CommentMentionService} after persist and create
 * one `mention` Notification per resolved recipient. The recipient
 * set is derived from the parent (task: owner + project space; page /
 * discussion: the parent's space).
 */
#[ApiResource(
    shortName: 'Comment',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.isAccessibleBy(user) and is_granted('space.comments.create', object)))",
            processor: CommentAuthorProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.isAccessibleBy(user) and is_granted('space.comments.read', object)))",
        ),
        new Patch(
            // Edit stays author-only (even admins/roles can't rewrite someone
            // else's words); create/read/delete are role-gated below.
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user)",
            processor: CommentUpdateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user or object.isDeletableBy(user) or (object.isAccessibleBy(user) and is_granted('space.comments.delete', object)))",
            processor: CommentDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['comment:read']],
    denormalizationContext: ['groups' => ['comment:write']],
    order: ['createdAt' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['task' => 'exact', 'page' => 'exact', 'discussion' => 'exact', 'feedback' => 'exact', 'commentableType' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'], arguments: ['orderParameterName' => 'order'])]
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(columns: ['task_id', 'created_at'], name: 'idx_comment_task_created')]
#[ORM\Index(columns: ['page_id', 'created_at'], name: 'idx_comment_page_created')]
#[ORM\Index(columns: ['discussion_id', 'created_at'], name: 'idx_comment_discussion_created')]
#[ORM\Index(columns: ['feedback_id', 'created_at'], name: 'idx_comment_feedback_created')]
#[ORM\Index(columns: ['search_vector'], name: 'idx_comment_search_vector', flags: ['gin'])]
#[ORM\HasLifecycleCallbacks]
class Comment
{
    public const MAX_BODY_LENGTH = 50_000;

    public const TYPE_TASK = 'task';
    public const TYPE_PAGE = 'page';
    public const TYPE_DISCUSSION = 'discussion';
    public const TYPE_FEEDBACK = 'feedback';

    public const ALLOWED_TYPES = [
        self::TYPE_TASK,
        self::TYPE_PAGE,
        self::TYPE_DISCUSSION,
        self::TYPE_FEEDBACK,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['comment:read'])]
    private ?Uuid $id = null;

    /**
     * `'task'`, `'page'`, or `'discussion'` — discriminator for the
     * polymorphic parent FK. Stamped server-side from whichever of
     * `task` / `page` / `discussion` the client supplied; never
     * accepted directly on the wire so clients can't desync it from
     * the FK trio.
     */
    #[ORM\Column(name: 'commentable_type', length: 16)]
    #[Assert\Choice(choices: self::ALLOWED_TYPES, message: 'Parent type must be task, page, discussion, or feedback.')]
    #[Groups(['comment:read'])]
    private string $commentableType = self::TYPE_TASK;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', nullable: true, onDelete: 'CASCADE')]
    #[Groups(['comment:read', 'comment:write'])]
    private ?Task $task = null;

    #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'page_id', nullable: true, onDelete: 'CASCADE')]
    #[Groups(['comment:read', 'comment:write'])]
    private ?Page $page = null;

    #[ORM\ManyToOne(targetEntity: Discussion::class)]
    #[ORM\JoinColumn(name: 'discussion_id', nullable: true, onDelete: 'CASCADE')]
    #[Groups(['comment:read', 'comment:write'])]
    private ?Discussion $discussion = null;

    #[ORM\ManyToOne(targetEntity: Feedback::class)]
    #[ORM\JoinColumn(name: 'feedback_id', nullable: true, onDelete: 'CASCADE')]
    #[Groups(['comment:read', 'comment:write'])]
    private ?Feedback $feedback = null;

    /**
     * Author is set server-side by {@see CommentAuthorProcessor} on
     * POST; the setter exists so PATCH can no-op the field, but the
     * serializer group keeps it read-only over the wire.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['comment:read'])]
    private ?User $author = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Comment body is required.')]
    #[Assert\Length(
        max: self::MAX_BODY_LENGTH,
        maxMessage: 'Comment cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['comment:read', 'comment:write'])]
    private string $body = '';

    /**
     * Postgres-managed full-text search vector over `body`, populated
     * by a STORED generated column. Mapped here so DQL can reference
     * `c.searchVector` from the task search filter's EXISTS subquery;
     * never written from PHP, never serialised.
     */
    #[ORM\Column(name: 'search_vector', type: 'text', nullable: true, insertable: false, updatable: false)]
    private ?string $searchVector = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['comment:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Bumped automatically on PATCH via the lifecycle hook; lets the
     * PWA render an "(edited)" affordance without diffing payloads.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['comment:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Keeps `commentable_type` aligned with whichever parent FK is
     * set. Runs on insert and update so a client that PATCHes
     * `task: null` + `page: '/pages/...'` flips the type cleanly. The
     * `validateParent` callback below enforces the exactly-one rule.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function syncCommentableType(): void
    {
        if (null !== $this->task) {
            $this->commentableType = self::TYPE_TASK;
        } elseif (null !== $this->page) {
            $this->commentableType = self::TYPE_PAGE;
        } elseif (null !== $this->discussion) {
            $this->commentableType = self::TYPE_DISCUSSION;
        } elseif (null !== $this->feedback) {
            $this->commentableType = self::TYPE_FEEDBACK;
        }
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCommentableType(): string
    {
        return $this->commentableType;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): static
    {
        $this->task = $task;
        if (null !== $task) {
            $this->commentableType = self::TYPE_TASK;
            $this->page = null;
            $this->discussion = null;
            $this->feedback = null;
        }
        return $this;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): static
    {
        $this->page = $page;
        if (null !== $page) {
            $this->commentableType = self::TYPE_PAGE;
            $this->task = null;
            $this->discussion = null;
            $this->feedback = null;
        }
        return $this;
    }

    public function getDiscussion(): ?Discussion
    {
        return $this->discussion;
    }

    public function setDiscussion(?Discussion $discussion): static
    {
        $this->discussion = $discussion;
        if (null !== $discussion) {
            $this->commentableType = self::TYPE_DISCUSSION;
            $this->task = null;
            $this->page = null;
            $this->feedback = null;
        }
        return $this;
    }

    public function getFeedback(): ?Feedback
    {
        return $this->feedback;
    }

    public function setFeedback(?Feedback $feedback): static
    {
        $this->feedback = $feedback;
        if (null !== $feedback) {
            $this->commentableType = self::TYPE_FEEDBACK;
            $this->task = null;
            $this->page = null;
            $this->discussion = null;
        }
        return $this;
    }

    /**
     * Returns the parent entity (Task, Page, Discussion, or Feedback)
     * the comment is attached to, or null if none is set yet (during
     * denormalization). Lets callers reach the parent without
     * branching on `commentableType`.
     */
    public function getCommentable(): Task|Page|Discussion|Feedback|null
    {
        return $this->task ?? $this->page ?? $this->discussion ?? $this->feedback;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Whether `$user` can read this comment. Delegates to the parent
     * entity's own accessibility rule — task uses owner-or-space-
     * member; page and discussion use space-member.
     */
    public function isAccessibleBy(User $user): bool
    {
        if (null !== $this->task) {
            return $this->task->isAccessibleBy($user);
        }
        if (null !== $this->page) {
            $space = $this->page->getSpace();
            return null !== $space && $space->hasMember($user);
        }
        if (null !== $this->discussion) {
            $space = $this->discussion->getSpace();
            return null !== $space && $space->hasMember($user);
        }
        // Feedback is an instance-level board: any authenticated user can
        // read every ticket, so its comments are readable too.
        if (null !== $this->feedback) {
            return true;
        }
        return false;
    }

    /**
     * Whether `$user` qualifies for the per-parent delete-escalation
     * path: task owner on a task comment, space admin on a page or
     * discussion comment. Feedback comments have no extra escalation —
     * author-self and platform admin (the security expression) are the
     * only deleters. Author-self deletion is handled by the security
     * expression separately.
     */
    public function isDeletableBy(User $user): bool
    {
        if (null !== $this->task) {
            $owner = $this->task->getOwner();
            return null !== $owner
                && null !== $owner->getId()
                && null !== $user->getId()
                && $owner->getId()->equals($user->getId());
        }
        if (null !== $this->page) {
            $space = $this->page->getSpace();
            return null !== $space && $space->isAdmin($user);
        }
        if (null !== $this->discussion) {
            $space = $this->discussion->getSpace();
            return null !== $space && $space->isAdmin($user);
        }
        return false;
    }

    /**
     * Exactly one of `task` / `page` / `discussion` must be set on
     * save. Anything else (more than one, none) is rejected — the FK
     * trio is the canonical parent reference and the discriminator
     * follows.
     */
    #[Assert\Callback]
    public function validateParent(ExecutionContextInterface $context): void
    {
        $set = (null !== $this->task ? 1 : 0)
            + (null !== $this->page ? 1 : 0)
            + (null !== $this->discussion ? 1 : 0)
            + (null !== $this->feedback ? 1 : 0);

        if ($set > 1) {
            $context->buildViolation('A comment cannot reference more than one parent.')
                ->atPath('task')
                ->addViolation();
            return;
        }
        if (0 === $set) {
            $context->buildViolation('A comment must reference a task, a page, a discussion, or a feedback ticket.')
                ->atPath('task')
                ->addViolation();
        }
    }

    /**
     * A locked discussion refuses *new* comments. The guard fires on
     * insert only (`null === id`) so existing comments on a thread
     * that was later locked stay editable and deletable; only fresh
     * replies are turned away. Matches the PWA, which hides the
     * composer behind the locked banner.
     */
    #[Assert\Callback]
    public function validateNotLocked(ExecutionContextInterface $context): void
    {
        if (null !== $this->id) {
            return;
        }
        if (null !== $this->discussion && $this->discussion->isLocked()) {
            $context->buildViolation('This discussion is locked.')
                ->atPath('discussion')
                ->addViolation();
        }
    }
}
