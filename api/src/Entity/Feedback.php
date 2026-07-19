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
use App\Repository\FeedbackRepository;
use App\State\FeedbackAggregateProvider;
use App\State\FeedbackOwnerProcessor;
use App\State\FeedbackUpdateProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An in-app feedback ticket — a bug report or a feature request.
 *
 * Instance-level (app-wide), NOT space-scoped: every authenticated user
 * sees and interacts with one shared board, the same way the waitlist
 * toggle and {@see Segment} cohorts are properties of the whole
 * deployment rather than of a space. There is therefore no access
 * extension — visibility is "any ROLE_USER" and the only per-row gate is
 * owner-or-admin on edit/delete (plus admin-only on the status field).
 *
 * Each ticket carries an auto-generated, monotonic {@see $ticketNumber}
 * stamped server-side by {@see FeedbackOwnerProcessor} from a dedicated
 * Postgres sequence (concurrency-safe, unlike MAX()+1) and read-only on
 * the wire afterwards — the same "stamp once on create" contract as the
 * group slug.
 *
 * Tickets survive their author's account deletion:
 * {@see \App\Service\AccountDeletionService} reassigns {@see $owner} to
 * the never-deletable "Former member" sentinel, exactly like tasks /
 * boards / pages, so the board's history stays intact.
 *
 * Reads are enriched by {@see FeedbackAggregateProvider} with the vote
 * {@see $score}, the caller's own {@see $myVote}, and the
 * {@see $commentCount} — all computed in a batched pass, no N+1.
 */
#[ApiResource(
    shortName: 'Feedback',
    operations: [
        new GetCollection(
            uriTemplate: '/feedback',
            security: "is_granted('ROLE_USER')",
            provider: FeedbackAggregateProvider::class,
        ),
        new Post(
            uriTemplate: '/feedback',
            security: "is_granted('ROLE_USER')",
            processor: FeedbackOwnerProcessor::class,
        ),
        new Get(
            uriTemplate: '/feedback/{id}',
            security: "is_granted('ROLE_USER')",
            provider: FeedbackAggregateProvider::class,
        ),
        new Patch(
            uriTemplate: '/feedback/{id}',
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user)",
            processor: FeedbackUpdateProcessor::class,
        ),
        new Delete(
            uriTemplate: '/feedback/{id}',
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user)",
        ),
    ],
    normalizationContext: ['groups' => ['feedback:read']],
    denormalizationContext: ['groups' => ['feedback:write']],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'ticketNumber'])]
#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
#[ORM\Table(name: 'feedback')]
#[ORM\UniqueConstraint(name: 'uniq_feedback_ticket_number', columns: ['ticket_number'])]
#[ORM\Index(columns: ['owner_id'], name: 'idx_feedback_owner')]
#[ORM\Index(columns: ['status'], name: 'idx_feedback_status')]
#[ORM\Index(columns: ['type'], name: 'idx_feedback_type')]
#[ORM\HasLifecycleCallbacks]
class Feedback
{
    public const TYPE_BUG = 'bug';
    public const TYPE_FEATURE = 'feature';
    public const TYPES = [self::TYPE_BUG, self::TYPE_FEATURE];

    public const STATUS_OPEN = 'open';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DECLINED = 'declined';
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SHIPPED,
        self::STATUS_DECLINED,
    ];

    public const MAX_TITLE_LENGTH = 200;
    public const MAX_DESCRIPTION_LENGTH = 50_000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['feedback:read'])]
    private ?Uuid $id = null;

    /**
     * Human-facing sequential reference ("#42"). Stamped once on create
     * by {@see FeedbackOwnerProcessor} from the `feedback_ticket_seq`
     * sequence; never accepted from the client and never changes — so
     * it's out of every write group.
     */
    #[ORM\Column(name: 'ticket_number', type: 'integer', unique: true)]
    #[Groups(['feedback:read'])]
    private ?int $ticketNumber = null;

    #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(
        max: self::MAX_TITLE_LENGTH,
        maxMessage: 'Title cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['feedback:read', 'feedback:write'])]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Description is required.')]
    #[Assert\Length(
        max: self::MAX_DESCRIPTION_LENGTH,
        maxMessage: 'Description cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['feedback:read', 'feedback:write'])]
    private string $description = '';

    /**
     * `bug` or `feature`. Settable on create + edit (an author can
     * recategorise their own ticket); the admin-only field is the
     * status below, enforced by {@see FeedbackUpdateProcessor}.
     */
    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: self::TYPES, message: 'Type must be "bug" or "feature".')]
    #[Groups(['feedback:read', 'feedback:write'])]
    private string $type = self::TYPE_BUG;

    /**
     * Workflow state. In `feedback:write` so it appears in the schema and
     * round-trips, but {@see FeedbackUpdateProcessor} rejects a change by
     * a non-admin — only platform admins move a ticket along the board.
     */
    #[ORM\Column(length: 16, options: ['default' => self::STATUS_OPEN])]
    #[Assert\Choice(choices: self::STATUSES, message: 'Unknown status.')]
    #[Groups(['feedback:read', 'feedback:write'])]
    private string $status = self::STATUS_OPEN;

    /**
     * The user who filed the ticket — stamped server-side on POST, never
     * trusted from the payload (same as Page.createdBy). Reassigned to
     * the "Former member" sentinel on account deletion rather than
     * cascade-deleted, so the ticket survives.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['feedback:read'])]
    private ?User $owner = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['feedback:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['feedback:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Aggregate vote score (sum of +1 / -1 votes) — computed at read
     * time by {@see FeedbackAggregateProvider}, never persisted.
     */
    private int $score = 0;

    /**
     * The calling user's own vote on this ticket: 1, -1, or 0 when they
     * haven't voted. Set per-request by the aggregate provider.
     */
    private int $myVote = 0;

    private int $commentCount = 0;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getTicketNumber(): ?int
    {
        return $this->ticketNumber;
    }

    public function setTicketNumber(?int $ticketNumber): static
    {
        $this->ticketNumber = $ticketNumber;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
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

    #[Groups(['feedback:read'])]
    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;
        return $this;
    }

    #[Groups(['feedback:read'])]
    public function getMyVote(): int
    {
        return $this->myVote;
    }

    public function setMyVote(int $myVote): static
    {
        $this->myVote = $myVote;
        return $this;
    }

    #[Groups(['feedback:read'])]
    public function getCommentCount(): int
    {
        return $this->commentCount;
    }

    public function setCommentCount(int $commentCount): static
    {
        $this->commentCount = $commentCount;
        return $this;
    }
}
