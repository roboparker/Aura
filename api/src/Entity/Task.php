<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use App\Filter\OverdueFilter;
use App\Filter\TaskSearchFilter;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\TaskRepository;
use App\State\TaskOwnerProcessor;
use App\State\TaskUpdateProcessor;
use App\Validator\ValidAssignees;
use App\Validator\ValidRecurrence;
use App\Validator\ValidReminders;
use App\Validator\ValidTaskAttachments;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            // The navbar badge polls `?overdue=true&itemsPerPage=1` so it can
            // read just `totalItems` and skip downloading task bodies. Without
            // client-controlled pagination the per-page size is fixed at the
            // 30-item default and the savings disappear.
            paginationClientItemsPerPage: true,
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: TaskOwnerProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user or (object.getProject() !== null and object.getProject().getMembers().contains(user)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user or (object.getProject() !== null and object.getProject().getMembers().contains(user)))",
            processor: TaskUpdateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user or (object.getProject() !== null and object.getProject().getMembers().contains(user)))",
        ),
    ],
    normalizationContext: ['groups' => ['task:read']],
    denormalizationContext: ['groups' => ['task:write']],
    order: ['position' => 'ASC', 'createdOn' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact', 'assignees' => 'exact'])]
#[ApiFilter(OverdueFilter::class)]
#[ApiFilter(TaskSearchFilter::class)]
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_task_owner')]
#[ORM\Index(columns: ['owner_id', 'position'], name: 'idx_task_owner_position')]
#[ORM\Index(columns: ['project_id'], name: 'idx_task_project')]
#[ValidAssignees]
#[ValidRecurrence]
#[ValidReminders]
#[ValidTaskAttachments]
#[Gedmo\Loggable(logEntryClass: ActivityLog::class)]
class Task
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['task:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['task:read'])]
    private ?User $owner = null;

    /**
     * Optional project the task belongs to. When set, every project member
     * can read and edit the task alongside its owner. Personal tasks leave
     * this null.
     */
    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['task:read', 'task:write'])]
    private ?Project $project = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(max: 255, maxMessage: 'Title cannot be longer than {{ limit }} characters.')]
    #[Groups(['task:read', 'task:write'])]
    #[Gedmo\Versioned]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 100000, maxMessage: 'Description cannot be longer than {{ limit }} characters.')]
    #[Groups(['task:read', 'task:write'])]
    #[Gedmo\Versioned]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['task:read'])]
    private \DateTimeImmutable $createdOn;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['task:read', 'task:write'])]
    #[Gedmo\Versioned]
    private ?\DateTimeImmutable $completedOn = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['task:read', 'task:write'])]
    #[Gedmo\Versioned]
    private ?\DateTimeImmutable $dueDate = null;

    /**
     * Optional recurrence rule. Persisted as a JSON object with shape
     * `{"frequency": "daily"|"weekly"|"monthly"|"yearly", "interval": int}`.
     * Validated in detail by {@see ValidRecurrence}; the cross-field rule
     * (recurrence requires a due date) lives there too. When set, completing
     * the task triggers {@see TaskUpdateProcessor} to clone the next
     * occurrence with `dueDate` advanced by the rule.
     *
     * @var array{frequency: string, interval: int}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['task:read', 'task:write'])]
    private ?array $recurrenceRule = null;

    /**
     * Reminder offsets to fire ahead of the due date. Each item is one of
     * the strings in {@see ValidReminders::ALLOWED_OFFSETS} (e.g. "15m",
     * "1h", "1d"). Empty array and null are equivalent — both mean "no
     * reminders". Validated by {@see ValidReminders}, which also enforces
     * the cross-field rule that reminders need a due date.
     *
     * @var string[]|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['task:read', 'task:write'])]
    private ?array $reminders = null;

    /**
     * Per-owner sort key. Lower positions render first. Set server-side:
     * assigned by the persist processor on create, rewritten in bulk by the
     * reorder endpoint. Negative values are allowed so new tasks can be
     * inserted at the top without having to shift existing rows.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['task:read'])]
    private int $position = 0;

    /**
     * Owning side of the Task↔Tag many-to-many. Membership is edited via
     * PATCH /tasks/{id} with a `tags` array of Tag IRIs. Tags are scoped to
     * the task's owner; cross-user IRIs are rejected by TagOwnerExtension
     * during deserialization.
     *
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'tasks')]
    #[ORM\JoinTable(name: 'task_tag')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['task:read', 'task:write'])]
    private Collection $tags;

    /**
     * Users assigned to this task. Always a subset of {owner ∪ project members}
     * — enforced by ValidAssignees on persist. Assignment is purely a "who's
     * responsible" label; it grants no extra read/edit privileges (those still
     * follow owner + project membership).
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'task_assignee')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['task:read', 'task:write'])]
    private Collection $assignees;

    /**
     * MediaObjects attached to this task. Membership is edited via PATCH
     * with an `attachments` array of MediaObject IRIs; the PWA uploads via
     * `POST /media-objects` (kind=attachment) first to obtain the IRI.
     * {@see ValidTaskAttachments} enforces that each attached MediaObject
     * belongs to the current user and is the right kind.
     *
     * @var Collection<int, MediaObject>
     */
    #[ORM\ManyToMany(targetEntity: MediaObject::class)]
    #[ORM\JoinTable(name: 'task_attachment')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'media_object_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['task:read', 'task:write'])]
    private Collection $attachments;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        $this->tags = new ArrayCollection();
        $this->assignees = new ArrayCollection();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCreatedOn(): \DateTimeImmutable
    {
        return $this->createdOn;
    }

    public function getCompletedOn(): ?\DateTimeImmutable
    {
        return $this->completedOn;
    }

    public function setCompletedOn(?\DateTimeImmutable $completedOn): static
    {
        $this->completedOn = $completedOn;
        return $this;
    }

    public function isCompleted(): bool
    {
        return null !== $this->completedOn;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    /**
     * @return array{frequency: string, interval: int}|null
     */
    public function getRecurrenceRule(): ?array
    {
        return $this->recurrenceRule;
    }

    /**
     * @param array{frequency: string, interval: int}|null $recurrenceRule
     */
    public function setRecurrenceRule(?array $recurrenceRule): static
    {
        $this->recurrenceRule = $recurrenceRule;
        return $this;
    }

    /**
     * @return string[]|null
     */
    public function getReminders(): ?array
    {
        return $this->reminders;
    }

    /**
     * @param string[]|null $reminders
     */
    public function setReminders(?array $reminders): static
    {
        // Normalise empty array to null so we have one canonical "no
        // reminders" representation for downstream queries.
        $this->reminders = (null === $reminders || [] === $reminders) ? null : array_values($reminders);
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getAssignees(): Collection
    {
        return $this->assignees;
    }

    public function addAssignee(User $user): static
    {
        if (!$this->assignees->contains($user)) {
            $this->assignees->add($user);
        }
        return $this;
    }

    public function removeAssignee(User $user): static
    {
        $this->assignees->removeElement($user);
        return $this;
    }

    /**
     * @return Collection<int, MediaObject>
     */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(MediaObject $media): static
    {
        if (!$this->attachments->contains($media)) {
            $this->attachments->add($media);
        }
        return $this;
    }

    public function removeAttachment(MediaObject $media): static
    {
        $this->attachments->removeElement($media);
        return $this;
    }
}
