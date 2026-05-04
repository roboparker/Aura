<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
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
 * Comment on a task. Read access mirrors the parent task — anyone who can
 * see the task (owner, project member, admin) can see its comments. Write
 * access is narrower: any reader can post; only the comment author can
 * edit; the author OR the task owner OR an admin can delete (so a project
 * lead can clean up after a teammate without needing to escalate).
 *
 * Replies are threaded via `parentComment`. Validation enforces that a
 * reply's parent belongs to the same task and that the chain depth stays
 * within {@see self::MAX_DEPTH}. Deleting a parent cascades to its subtree
 * via the FK ON DELETE CASCADE.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getTask().getOwner() == user or (object.getTask().getProject() !== null and object.getTask().getProject().getMembers().contains(user)))",
            processor: CommentAuthorProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getTask().getOwner() == user or (object.getTask().getProject() !== null and object.getTask().getProject().getMembers().contains(user)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user)",
            processor: CommentUpdateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user or object.getTask().getOwner() == user)",
            processor: CommentDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['comment:read']],
    denormalizationContext: ['groups' => ['comment:write']],
    order: ['createdAt' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['task' => 'exact', 'parentComment' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'], arguments: ['orderParameterName' => 'order'])]
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(columns: ['task_id', 'created_at'], name: 'idx_comment_task_created')]
#[ORM\Index(columns: ['parent_comment_id'], name: 'idx_comment_parent')]
#[ORM\HasLifecycleCallbacks]
class Comment
{
    public const MAX_BODY_LENGTH = 10_000;

    /**
     * Maximum thread depth, counting the root comment as depth 1. A reply
     * to the root is depth 2, a reply-to-a-reply is depth 3, and that's
     * the cap — replying to a depth-3 comment is rejected. Matches the
     * visual indent cap on the frontend.
     */
    public const MAX_DEPTH = 3;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['comment:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Task is required.')]
    #[Groups(['comment:read', 'comment:write'])]
    private ?Task $task = null;

    /**
     * Author is set server-side by CommentAuthorProcessor on POST; the
     * setter exists so PATCH can no-op the field, but the serializer
     * group keeps it read-only over the wire.
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
     * Postgres-managed full-text search vector over `body`, populated by
     * a STORED generated column — see Version20260504090000. Mapped here
     * so DQL can reference `c.searchVector` from the task search filter's
     * EXISTS subquery; never written from PHP, never serialised.
     */
    #[ORM\Column(name: 'search_vector', type: 'text', nullable: true, insertable: false, updatable: false)]
    private ?string $searchVector = null;

    /**
     * Self-referencing parent for threaded replies. Nullable for root
     * comments. ON DELETE CASCADE means deleting a parent drops its
     * subtree in one shot — depth is bounded so the cascade stays small.
     *
     * `readableLink: false` keeps the field serialized as a bare IRI on
     * the read side; the default would inline the parent (and its parent,
     * recursively) and quickly bloat the payload on a long thread.
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'parent_comment_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    #[Groups(['comment:read', 'comment:write'])]
    private ?Comment $parentComment = null;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Comment>
     */
    #[ORM\OneToMany(mappedBy: 'parentComment', targetEntity: self::class)]
    private \Doctrine\Common\Collections\Collection $replies;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['comment:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Bumped automatically on PATCH via the lifecycle hook; lets the PWA
     * render an "(edited)" affordance without diffing payloads.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['comment:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->replies = new \Doctrine\Common\Collections\ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): static
    {
        $this->task = $task;
        return $this;
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

    public function getParentComment(): ?Comment
    {
        return $this->parentComment;
    }

    public function setParentComment(?Comment $parentComment): static
    {
        $this->parentComment = $parentComment;
        return $this;
    }

    /**
     * Walks up the chain to compute this comment's depth (root = 1). Stops
     * one past {@see self::MAX_DEPTH} so the validator can reject without
     * looping forever if somebody manages to seed a cycle through the
     * back door (e.g. fixtures).
     */
    public function getDepth(): int
    {
        $depth = 1;
        $cursor = $this->parentComment;
        while ($cursor !== null && $depth <= self::MAX_DEPTH + 1) {
            $depth++;
            $cursor = $cursor->getParentComment();
        }
        return $depth;
    }

    #[Assert\Callback]
    public function validateParentComment(ExecutionContextInterface $context): void
    {
        $parent = $this->parentComment;
        if ($parent === null) {
            return;
        }

        if ($parent === $this) {
            $context->buildViolation('A comment cannot reply to itself.')
                ->atPath('parentComment')
                ->addViolation();
            return;
        }

        if ($this->task !== null
            && $parent->getTask() !== null
            && !$this->task->getId()?->equals($parent->getTask()->getId())
        ) {
            $context->buildViolation('Reply must be on the same task as its parent.')
                ->atPath('parentComment')
                ->addViolation();
        }

        if ($parent->getDepth() >= self::MAX_DEPTH) {
            $context->buildViolation(sprintf(
                'Replies cannot be nested more than %d levels deep.',
                self::MAX_DEPTH,
            ))
                ->atPath('parentComment')
                ->addViolation();
        }
    }
}
