<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
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
 * In-app notification for the recipient. Today the only kind is
 * `task_reminder` (created by App\Command\DispatchTaskRemindersCommand);
 * the `type` field leaves room for other kinds later (mentions, invites, …)
 * without splitting the table.
 *
 * Read access is per-user — a Doctrine query extension scopes listings to
 * the current recipient. Mark-as-read is the only allowed mutation, gated
 * by App\State\NotificationUpdateProcessor so the rest of the row stays
 * server-controlled.
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
#[ApiFilter(ExistsFilter::class, properties: ['readAt'])]
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(columns: ['recipient_id', 'read_at'], name: 'idx_notification_recipient_read')]
#[ORM\UniqueConstraint(
    name: 'uniq_task_reminder',
    columns: ['recipient_id', 'task_id', 'reminder_offset'],
)]
class Notification
{
    public const TYPE_TASK_REMINDER = 'task_reminder';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['notification:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $recipient = null;

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
     * Set by NotificationUpdateProcessor when the user marks the row read.
     * `null` means unread; any non-null value means read.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['notification:read', 'notification:write'])]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['notification:read'])]
    private \DateTimeImmutable $createdAt;

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
}
