<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use App\Filter\OverdueFilter;
use App\Filter\TaskSearchFilter;
use App\Filter\TaskStatusFilter;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\TaskRepository;
use App\State\TaskOwnerProcessor;
use App\State\TaskSearchProvenanceProvider;
use App\State\TaskUpdateProcessor;
use App\Validator\ValidAssignees;
use App\Validator\ValidCustomFieldValues;
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
            // Annotates results with comment / custom-field match provenance
            // when `?search=` is present (no-op otherwise; delegates to the
            // stock provider for access scoping + pagination).
            provider: TaskSearchProvenanceProvider::class,
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: TaskOwnerProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAccessibleBy(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAccessibleBy(user))",
            processor: TaskUpdateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAccessibleBy(user))",
        ),
    ],
    normalizationContext: ['groups' => ['task:read']],
    denormalizationContext: ['groups' => ['task:write']],
    order: ['position' => 'ASC', 'createdOn' => 'DESC'],
)]
// `project.space` lets the space detail page list every task across
// every project in a space without doing a fan-out fetch over each
// project. Access scoping still goes through TaskOwnerExtension +
// SpaceMembershipDql so this can't widen what the caller can see.
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact', 'project.space' => 'exact', 'assignees' => 'exact', 'tags' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['dueDate'])]
#[ApiFilter(OrderFilter::class, properties: ['createdOn', 'dueDate', 'title', 'completedOn'], arguments: ['orderParameterName' => 'order'])]
#[ApiFilter(OverdueFilter::class)]
#[ApiFilter(TaskSearchFilter::class)]
#[ApiFilter(TaskStatusFilter::class)]
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_task_owner')]
#[ORM\Index(columns: ['owner_id', 'position'], name: 'idx_task_owner_position')]
#[ORM\Index(columns: ['project_id'], name: 'idx_task_project')]
// GIN index over the FTS-only generated column. The `gin` flag is the
// PostgreSQL DBAL platform's hook for emitting `USING GIN`. Declared
// here (in addition to the migration) so doctrine:schema:validate
// stops trying to drop it on every CI run.
#[ORM\Index(columns: ['search_vector'], name: 'idx_task_search_vector', flags: ['gin'])]
#[ValidAssignees]
#[ValidRecurrence]
#[ValidReminders]
#[ValidTaskAttachments]
#[ValidCustomFieldValues]
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
    // Versioned so the audit log records which project a task belonged to —
    // the board's activity feed keys on this to keep a deleted task's history
    // (ending in its remove event) even after the row is gone.
    #[Gedmo\Versioned]
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

    /**
     * Postgres-managed full-text search vector (title + description),
     * populated by a STORED generated column — see Version20260504090000.
     * Mapped here so DQL can reference `t.searchVector` in the search
     * filter; never written from PHP, never serialised in API responses.
     */
    #[ORM\Column(name: 'search_vector', type: 'text', nullable: true, insertable: false, updatable: false)]
    private ?string $searchVector = null;

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
     * Optional recurrence rule. Persisted as a JSON object. The base shape is
     * `{"frequency": "daily"|"weekly"|"monthly"|"yearly", "interval": int}`,
     * optionally extended with `byDay` (weekday tokens), `monthlyMode`,
     * `bySetPos`, and an `ends` end-condition — see
     * {@see \App\Service\RecurrenceCalculator} for the full shape. Validated
     * by {@see ValidRecurrence} (and the cross-field rule that recurrence
     * requires a due date). When set, completing the task triggers
     * {@see TaskUpdateProcessor} to clone the next occurrence.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['task:read', 'task:write'])]
    private ?array $recurrenceRule = null;

    /**
     * Reminders to fire around the due date. Each entry is an object — either
     * relative (`{type:"relative", value:int, unit:"minutes"|"hours"|"days",
     * repeat:bool}`, fired `value` units before the due date) or absolute
     * (`{type:"absolute", at:ISO-8601, repeat:bool}`, fired at a fixed time).
     * `repeat` means "repeat daily until done". Empty array and null are
     * equivalent — both mean "no reminders". Validated by {@see ValidReminders}.
     * Typed loosely (`list<mixed>`) because the JSON payload isn't shape-checked
     * until the validator runs — entries are guarded with `is_array()` there.
     *
     * @var list<mixed>|null
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

    /**
     * Per-task values for the project's {@see CustomFieldDefinition}s
     * (#84). Mutated via the Task write group: clients PATCH a fresh
     * array of `{definition, value}` pairs and orphanRemoval reaps any
     * row whose definition isn't in the new payload. Type/required/scope
     * rules are enforced by {@see ValidCustomFieldValues}.
     *
     * @var Collection<int, CustomFieldValue>
     */
    #[ORM\OneToMany(
        mappedBy: 'task',
        targetEntity: CustomFieldValue::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[Groups(['task:read', 'task:write'])]
    private Collection $customFieldValues;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        $this->tags = new ArrayCollection();
        $this->assignees = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->customFieldValues = new ArrayCollection();
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

    /**
     * Convenience for security expressions and controllers: a task is
     * readable/editable by its owner, or by any member of the parent
     * project's space (#185). Standalone tasks (no project) are
     * owner-only — there's no space to inherit from.
     */
    public function isAccessibleBy(?User $user): bool
    {
        if (null === $user) {
            return false;
        }
        if (null !== $this->owner && $this->owner === $user) {
            return true;
        }
        return null !== $this->project && $this->project->isAccessibleBy($user);
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
     * @return array<string, mixed>|null
     */
    public function getRecurrenceRule(): ?array
    {
        return $this->recurrenceRule;
    }

    /**
     * @param array<string, mixed>|null $recurrenceRule
     */
    public function setRecurrenceRule(?array $recurrenceRule): static
    {
        $this->recurrenceRule = $recurrenceRule;
        return $this;
    }

    /**
     * @return list<mixed>|null
     */
    public function getReminders(): ?array
    {
        return $this->reminders;
    }

    /**
     * @param list<mixed>|null $reminders
     */
    public function setReminders(?array $reminders): static
    {
        // Normalise empty array to null so we have one canonical "no
        // reminders" representation for downstream queries. Input is already
        // a list, so no array_values reindex is needed.
        $this->reminders = (null === $reminders || [] === $reminders) ? null : $reminders;
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

    /**
     * @return Collection<int, CustomFieldValue>
     */
    public function getCustomFieldValues(): Collection
    {
        return $this->customFieldValues;
    }

    public function addCustomFieldValue(CustomFieldValue $value): static
    {
        if (!$this->customFieldValues->contains($value)) {
            $this->customFieldValues->add($value);
            $value->setTask($this);
        }
        return $this;
    }

    public function removeCustomFieldValue(CustomFieldValue $value): static
    {
        if ($this->customFieldValues->removeElement($value)) {
            if ($value->getTask() === $this) {
                $value->setTask(null);
            }
        }
        return $this;
    }

    /**
     * Search match provenance for the hidden sources (comment / custom
     * field), computed at read time by
     * {@see App\State\TaskSearchProvenanceProvider} only when `?search=`
     * is present. Empty otherwise. Never persisted.
     *
     * @var list<array{source: string, label: string, snippet: string}>
     */
    private array $searchMatches = [];

    /**
     * @return list<array{source: string, label: string, snippet: string}>
     */
    #[Groups(['task:read'])]
    public function getSearchMatches(): array
    {
        return $this->searchMatches;
    }

    /**
     * @param list<array{source: string, label: string, snippet: string}> $searchMatches
     */
    public function setSearchMatches(array $searchMatches): static
    {
        $this->searchMatches = $searchMatches;
        return $this;
    }
}
