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
 * discriminates between `'task'` and `'page'`, and exactly one of
 * `task` / `page` is set to match. Both `task_id` and `page_id` are
 * real FKs (rather than a single bare `commentable_id`) so we keep
 * cascade-on-delete from the parent.
 *
 * Comments are flat (no reply tree) and ordered chronologically. The
 * previous task-side reply tree was flattened in the unification
 * migration; the chronological view is the canonical thread surface.
 *
 * Access is parent-aware:
 *   - read: anyone who can read the parent (task accessibility OR
 *     page space membership).
 *   - post: same set as read.
 *   - edit: author only.
 *   - delete: author, OR an admin escalation suited to the parent —
 *     the task's owner can delete on tasks; the page's space-admin
 *     can delete on pages.
 *
 * `@mention` tokens in `body` are scanned by
 * {@see App\Service\CommentMentionService} after persist and create
 * one `mention` Notification per resolved recipient. The recipient
 * set is derived from the parent (task: owner + project space; page:
 * page's space).
 */
#[ApiResource(
    shortName: 'Comment',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAccessibleBy(user))",
            processor: CommentAuthorProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAccessibleBy(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user)",
            processor: CommentUpdateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user or object.isDeletableBy(user))",
            processor: CommentDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['comment:read']],
    denormalizationContext: ['groups' => ['comment:write']],
    order: ['createdAt' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['task' => 'exact', 'page' => 'exact', 'commentableType' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'], arguments: ['orderParameterName' => 'order'])]
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(columns: ['task_id', 'created_at'], name: 'idx_comment_task_created')]
#[ORM\Index(columns: ['page_id', 'created_at'], name: 'idx_comment_page_created')]
#[ORM\Index(columns: ['search_vector'], name: 'idx_comment_search_vector', flags: ['gin'])]
#[ORM\HasLifecycleCallbacks]
class Comment
{
    public const MAX_BODY_LENGTH = 50_000;

    public const TYPE_TASK = 'task';
    public const TYPE_PAGE = 'page';

    public const ALLOWED_TYPES = [self::TYPE_TASK, self::TYPE_PAGE];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['comment:read'])]
    private ?Uuid $id = null;

    /**
     * `'task'` or `'page'` — discriminator for the polymorphic parent
     * FK. Stamped server-side from whichever of `task` / `page` the
     * client supplied; never accepted directly on the wire so clients
     * can't desync it from the FK pair.
     */
    #[ORM\Column(name: 'commentable_type', length: 8)]
    #[Assert\Choice(choices: self::ALLOWED_TYPES, message: 'Parent type must be task or page.')]
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
        }
        return $this;
    }

    /**
     * Returns the parent entity (Task or Page) the comment is
     * attached to, or null if neither is set yet (during
     * denormalization). Lets callers reach the parent without
     * branching on `commentableType`.
     */
    public function getCommentable(): Task|Page|null
    {
        return $this->task ?? $this->page;
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
     * member; page uses space-member.
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
        return false;
    }

    /**
     * Whether `$user` qualifies for the per-parent delete-escalation
     * path: task owner on a task comment, space admin on a page
     * comment. Author-self deletion is handled by the security
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
        return false;
    }

    /**
     * Exactly one of `task` / `page` must be set on save. Anything
     * else (both, neither) is rejected — the FK pair is the canonical
     * parent reference and the discriminator follows.
     */
    #[Assert\Callback]
    public function validateParent(ExecutionContextInterface $context): void
    {
        $hasTask = null !== $this->task;
        $hasPage = null !== $this->page;

        if ($hasTask && $hasPage) {
            $context->buildViolation('A comment cannot reference both a task and a page.')
                ->atPath('task')
                ->addViolation();
            return;
        }
        if (!$hasTask && !$hasPage) {
            $context->buildViolation('A comment must reference either a task or a page.')
                ->atPath('task')
                ->addViolation();
        }
    }
}
