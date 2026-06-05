<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * In-app notification for the recipient. Kinds: `task_reminder`
 * (App\Command\DispatchTaskRemindersCommand), `mention` / `reply` /
 * `comment` (comment activity, via App\Service\NotificationDispatcher),
 * and `assigned` / `status` (task activity). Most carry an `actor` (the
 * user who triggered it) and resolve a deep-link `target` from whichever
 * of task / comment / discussion / page is set.
 *
 * Read access is per-user — App\Doctrine\NotificationRecipientExtension
 * scopes listings to the current recipient. The only client-writable
 * fields are `readAt` and `archivedAt` (mark-read / archive); everything
 * else is server-controlled. Bulk mark-read / archive live on
 * App\Controller\NotificationBulkController; the live bell subscribes to
 * the per-user Mercure topic minted by NotificationsMercureTokenController.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getRecipient() == user)",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getRecipient() == user)",
            // Mark-as-read only — see NotificationUpdateProcessor for the
            // narrow allowlist of fields the user can actually change.
        ),
    ],
    normalizationContext: ['groups' => ['notification:read']],
    denormalizationContext: ['groups' => ['notification:write']],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(ExistsFilter::class, properties: ['readAt', 'archivedAt'])]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact'])]
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(columns: ['recipient_id', 'read_at'], name: 'idx_notification_recipient_read')]
#[ORM\Index(columns: ['recipient_id', 'archived_at'], name: 'idx_notification_recipient_archived')]
#[ORM\UniqueConstraint(
    name: 'uniq_task_reminder',
    columns: ['recipient_id', 'task_id', 'reminder_offset'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_comment_mention',
    columns: ['recipient_id', 'comment_id'],
)]
class Notification
{
    public const TYPE_TASK_REMINDER = 'task_reminder';
    /**
     * @-mention notification — fires when an `@<handle>` token in a
     * {@see Comment} body resolves to a user the comment author
     * isn't (#228). Replaces the pre-unification `task_mention` type;
     * the migration backfills existing rows.
     */
    public const TYPE_MENTION = 'mention';
    /**
     * @deprecated Use {@see self::TYPE_MENTION}. Kept as a class
     *             constant alias so tests / external code referencing
     *             the old name still compile. Will be removed after
     *             one release.
     */
    public const TYPE_TASK_MENTION = self::TYPE_MENTION;

    /** Someone replied in a thread you posted in (not an @mention of you). */
    public const TYPE_REPLY = 'reply';
    /** New comment on a task you own / a thread you started. */
    public const TYPE_COMMENT = 'comment';
    /** A task was assigned to you. */
    public const TYPE_ASSIGNED = 'assigned';
    /** A task you own or are assigned to changed status (e.g. completed). */
    public const TYPE_STATUS = 'status';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['notification:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $recipient = null;

    /**
     * Who triggered the notification (commenter, assigner, …). Null for
     * system-generated rows like reminders. Embedded under
     * `notification:read` so the row renders an actor avatar without a
     * follow-up fetch. SET NULL on actor deletion so the row survives.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['notification:read'])]
    private ?User $actor = null;

    #[ORM\Column(length: 50)]
    #[Groups(['notification:read'])]
    private string $type = self::TYPE_TASK_REMINDER;

    #[ORM\Column(length: 255)]
    #[Groups(['notification:read'])]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['notification:read'])]
    private ?string $body = null;

    /**
     * Optional link back to the task this notification refers to.
     * Exposed as the bare IRI under `notification:read` so the PWA can
     * deep-link without a second fetch.
     */
    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['notification:read'])]
    private ?Task $task = null;

    /**
     * Reminder offset string (e.g. "15m", "1h", "1d") this row was created
     * for. Used together with (recipient, task) as a uniqueness key so the
     * dispatcher can be re-run safely without producing duplicate rows.
     */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $reminderOffset = null;

    /**
     * Comment that triggered this notification — only set for
     * `mention` rows. Together with `recipient` it forms a unique key
     * so editing a comment can't re-fire mentions to the same user.
     * The bare IRI is exposed under `notification:read` so the PWA
     * can deep-link to the comment without an extra fetch.
     */
    #[\ApiPlatform\Metadata\ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Comment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['notification:read'])]
    private ?Comment $comment = null;

    /**
     * Discussion this notification points at, when it isn't comment- or
     * task-derived. Internal — the deep-link is exposed via the computed
     * `targetUrl` getter rather than a raw IRI.
     */
    #[ORM\ManyToOne(targetEntity: Discussion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Discussion $discussion = null;

    /**
     * Page this notification points at, when it isn't comment- or
     * task-derived. Internal — see `targetUrl`.
     */
    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Page $page = null;

    /**
     * Set by NotificationUpdateProcessor when the user marks the row read.
     * `null` means unread; any non-null value means read.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['notification:read', 'notification:write'])]
    private ?\DateTimeImmutable $readAt = null;

    /**
     * Set when the user archives the row (single PATCH or the bulk
     * archive endpoint). `null` means it's still in the active inbox;
     * any value means archived ("View archived" surfaces these).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['notification:read', 'notification:write'])]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['notification:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Stamped by App\Command\DispatchNotificationDigestCommand once a row
     * has been included in a digest email so the next digest run skips it.
     * Independent of {@see $readAt} — a digest can ship before the user
     * opens the bell, and a user can read a notification without ever
     * having received a digest (realtime path).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['notification:read'])]
    private ?\DateTimeImmutable $digestedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function setRecipient(?User $recipient): static
    {
        $this->recipient = $recipient;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;
        return $this;
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

    public function getReminderOffset(): ?string
    {
        return $this->reminderOffset;
    }

    public function setReminderOffset(?string $reminderOffset): static
    {
        $this->reminderOffset = $reminderOffset;
        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(?\DateTimeImmutable $readAt): static
    {
        $this->readAt = $readAt;
        return $this;
    }

    /**
     * Convenience boolean for the BooleanFilter on `read`. Hides the
     * timestamp from the filter API surface — clients pass `?read=true`
     * or `?read=false`, never a date.
     */
    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDigestedAt(): ?\DateTimeImmutable
    {
        return $this->digestedAt;
    }

    public function setDigestedAt(?\DateTimeImmutable $digestedAt): static
    {
        $this->digestedAt = $digestedAt;
        return $this;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function setComment(?Comment $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): static
    {
        $this->actor = $actor;
        return $this;
    }

    public function getDiscussion(): ?Discussion
    {
        return $this->discussion;
    }

    public function setDiscussion(?Discussion $discussion): static
    {
        $this->discussion = $discussion;
        return $this;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): static
    {
        $this->page = $page;
        return $this;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;
        return $this;
    }

    /**
     * Front-end deep-link for the row, resolved from whichever parent is
     * set (comment unwraps to its own parent). Null when nothing is
     * linkable. Computed at read time — never persisted.
     */
    #[Groups(['notification:read'])]
    public function getTargetUrl(): ?string
    {
        return $this->resolveTarget()['url'];
    }

    /** Title of the linked entity (task / discussion / page title). */
    #[Groups(['notification:read'])]
    public function getTargetLabel(): ?string
    {
        return $this->resolveTarget()['label'];
    }

    /** Breadcrumb context — the parent project or space name. */
    #[Groups(['notification:read'])]
    public function getContextLabel(): ?string
    {
        return $this->resolveTarget()['context'];
    }

    /**
     * @return array{url: ?string, label: ?string, context: ?string}
     */
    private function resolveTarget(): array
    {
        $task = $this->task;
        $comment = $this->comment;
        if (null !== $comment) {
            if (null !== $comment->getTask()) {
                $task = $comment->getTask();
            } elseif (null !== $comment->getPage()) {
                return $this->pageTarget($comment->getPage());
            } elseif (null !== $comment->getDiscussion()) {
                return $this->discussionTarget($comment->getDiscussion());
            }
        }
        if (null !== $task) {
            return $this->taskTarget($task);
        }
        if (null !== $this->discussion) {
            return $this->discussionTarget($this->discussion);
        }
        if (null !== $this->page) {
            return $this->pageTarget($this->page);
        }

        return ['url' => null, 'label' => null, 'context' => null];
    }

    /**
     * @return array{url: ?string, label: ?string, context: ?string}
     */
    private function taskTarget(Task $task): array
    {
        $project = $task->getProject();

        return [
            'url' => '/tasks',
            'label' => $task->getTitle(),
            'context' => null === $project ? 'Tasks' : $project->getTitle(),
        ];
    }

    /**
     * @return array{url: ?string, label: ?string, context: ?string}
     */
    private function discussionTarget(Discussion $discussion): array
    {
        $id = $discussion->getId();

        return [
            'url' => null === $id ? null : '/discussions/' . $id,
            'label' => $discussion->getTitle(),
            'context' => $discussion->getSpace()?->getName(),
        ];
    }

    /**
     * @return array{url: ?string, label: ?string, context: ?string}
     */
    private function pageTarget(Page $page): array
    {
        $id = $page->getId();

        return [
            'url' => null === $id ? null : '/pages/' . $id,
            'label' => $page->getTitle(),
            'context' => $page->getSpace()?->getName(),
        ];
    }
}
