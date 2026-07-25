<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Filter\BoardSearchFilter;
use App\Repository\BoardRepository;
use App\State\BoardOwnerProcessor;
use App\State\BoardTimelineProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Shared container for tasks. Lives inside a {@see Space} (#181), and
 * access is determined by membership in that space — every space member
 * can read, edit, and (subject to admin/author rules) delete the
 * board and its tasks. The `owner` field records who created the
 * board for display/audit — it does not grant extra privileges
 * beyond what space membership already provides.
 *
 * Per #185:
 *  - read/list/create: any space member
 *  - edit: any space member (board metadata is shared state)
 *  - delete: board creator (owner) or space admin
 *  - Post requires the caller to be a member of the target space
 *    (enforced via securityPostDenormalize so the validator runs after
 *    the IRI denormalises into a Space entity).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            // Allow `space === null` so the existing PWA — which doesn't
            // know about spaces yet — can keep posting boards without
            // an explicit IRI; BoardOwnerProcessor will default it to
            // the caller's personal space (where they're admin) before
            // persist. When the client DOES pick a space, they must be a
            // member of it.
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace() === null or (object.isAccessibleBy(user) and is_granted('space.boards.create', object)))",
            securityPostDenormalizeMessage: 'You can only create boards in a space you belong to.',
            processor: BoardOwnerProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.isAccessibleBy(user) and is_granted('space.boards.read', object)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.isAccessibleBy(user) and is_granted('space.boards.update', object)))",
            processor: BoardTimelineProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user or object.isSpaceAdmin(user) or (object.isAccessibleBy(user) and is_granted('space.boards.delete', object)))",
            securityMessage: 'Only the board creator or a space admin can delete a board.',
        ),
    ],
    normalizationContext: ['groups' => ['board:read']],
    denormalizationContext: ['groups' => ['board:write']],
    order: ['createdOn' => 'DESC'],
)]
#[ORM\Entity(repositoryClass: BoardRepository::class)]
#[ORM\Table(name: 'board')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_board_owner')]
#[ORM\Index(columns: ['space_id'], name: 'idx_board_space')]
// Mirror the GIN index on `search_vector` from Version20260506010000 so
// doctrine:schema:validate doesn't try to drop it on every CI run.
#[ORM\Index(columns: ['search_vector'], name: 'idx_board_search_vector', flags: ['gin'])]
#[ApiFilter(BoardSearchFilter::class)]
#[ApiFilter(\ApiPlatform\Doctrine\Orm\Filter\SearchFilter::class, properties: ['space' => 'exact'])]
#[Gedmo\Loggable(logEntryClass: ActivityLog::class)]
class Board
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['board:read', 'task:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['board:read'])]
    private ?User $owner = null;

    /**
     * The space this board lives in (#181). Set by BoardOwnerProcessor
     * to the creator's personal space when the client doesn't specify
     * one — keeps the existing PWA, which is unaware of spaces, working
     * unchanged. Made non-null at the DB level once the migration's
     * backfill populates every existing row.
     *
     * Owner-based access is still in force in PR 1; PR 2 (#185) swaps
     * the access predicates to scope by space membership.
     */
    #[ORM\ManyToOne(targetEntity: Space::class, inversedBy: 'boards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['board:read', 'board:write'])]
    private ?Space $space = null;

    /**
     * The project this task-management board rolls up to (Harvest
     * model). Nullable — set from the project's page. `SET NULL` so
     * deleting a project just unassigns its boards.
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'assignedBoards')]
    #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['board:read'])]
    private ?Project $project = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(max: 255, maxMessage: 'Title cannot be longer than {{ limit }} characters.')]
    #[Groups(['board:read', 'board:write', 'task:read'])]
    #[Gedmo\Versioned]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 100000, maxMessage: 'Description cannot be longer than {{ limit }} characters.')]
    #[Groups(['board:read', 'board:write'])]
    #[Gedmo\Versioned]
    private ?string $description = null;

    /**
     * Postgres-managed full-text search vector (title + description),
     * populated by a STORED generated column — see Version20260506010000.
     * Mapped here so DQL can reference `p.searchVector` in the search
     * filter; never written from PHP, never serialised in API responses.
     */
    #[ORM\Column(name: 'search_vector', type: 'text', nullable: true, insertable: false, updatable: false)]
    private ?string $searchVector = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['board:read'])]
    private \DateTimeImmutable $createdOn;

    /**
     * @var Collection<int, Task>
     */
    #[ORM\OneToMany(mappedBy: 'board', targetEntity: Task::class, fetch: 'EXTRA_LAZY')]
    private Collection $tasks;

    /**
     * Custom-field definitions (owned by this board's space) this board
     * shows on its tasks. Owning side of the M2M (#custom-fields-space);
     * exposed as IRIs so the board Settings picker can read/set the subset.
     *
     * @var Collection<int, CustomFieldDefinition>
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToMany(targetEntity: CustomFieldDefinition::class, inversedBy: 'boards')]
    #[ORM\JoinTable(name: 'board_custom_field_definition')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'custom_field_definition_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['board:read', 'board:write'])]
    private Collection $customFieldDefinitions;

    /**
     * Instance-wide global custom-field definitions this board opts into
     * (#global-custom-fields). Same per-board chooser as the space fields
     * above, but a separate join table — a board's effective field set is
     * the union of the two. Global definitions are admin-managed; a board
     * member can only toggle them on/off here (write) — never edit them.
     *
     * @var Collection<int, GlobalCustomFieldDefinition>
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToMany(targetEntity: GlobalCustomFieldDefinition::class, inversedBy: 'boards')]
    #[ORM\JoinTable(name: 'board_global_custom_field_definition')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'global_custom_field_definition_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['board:read', 'board:write'])]
    private Collection $globalCustomFieldDefinitions;

    /**
     * Timeline (#timeline) opt-in. When true, the board shows the Gantt tab and
     * the canonical global "Start date" field
     * ({@see GlobalCustomFieldDefinition::SYSTEM_TIMELINE_START}) is attached to
     * this board's field set — each task's bar runs from that field to its
     * native `dueDate`. {@see \App\State\BoardTimelineProcessor} keeps the field
     * attached for as long as this is true (re-adding it if a request tries to
     * drop it), so turning Timeline off is the only way to remove it.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['board:read', 'board:write'])]
    private bool $timelineEnabled = false;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        $this->tasks = new ArrayCollection();
        $this->customFieldDefinitions = new ArrayCollection();
        $this->globalCustomFieldDefinitions = new ArrayCollection();
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

    public function getSpace(): ?Space
    {
        return $this->space;
    }

    public function setSpace(?Space $space): static
    {
        $this->space = $space;
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

    /**
     * @return Collection<int, Task>
     */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    /**
     * @return Collection<int, CustomFieldDefinition>
     */
    public function getCustomFieldDefinitions(): Collection
    {
        return $this->customFieldDefinitions;
    }

    public function addCustomFieldDefinition(CustomFieldDefinition $definition): static
    {
        if (!$this->customFieldDefinitions->contains($definition)) {
            $this->customFieldDefinitions->add($definition);
        }
        return $this;
    }

    public function removeCustomFieldDefinition(CustomFieldDefinition $definition): static
    {
        $this->customFieldDefinitions->removeElement($definition);
        return $this;
    }

    /**
     * @return Collection<int, GlobalCustomFieldDefinition>
     */
    public function getGlobalCustomFieldDefinitions(): Collection
    {
        return $this->globalCustomFieldDefinitions;
    }

    public function addGlobalCustomFieldDefinition(GlobalCustomFieldDefinition $definition): static
    {
        if (!$this->globalCustomFieldDefinitions->contains($definition)) {
            $this->globalCustomFieldDefinitions->add($definition);
        }
        return $this;
    }

    public function removeGlobalCustomFieldDefinition(GlobalCustomFieldDefinition $definition): static
    {
        $this->globalCustomFieldDefinitions->removeElement($definition);
        return $this;
    }

    public function isTimelineEnabled(): bool
    {
        return $this->timelineEnabled;
    }

    public function setTimelineEnabled(bool $enabled): static
    {
        $this->timelineEnabled = $enabled;
        return $this;
    }

    /**
     * Total task count, surfaced on the wire for list/dashboard rows.
     * EXTRA_LAZY makes this a COUNT query rather than hydrating the
     * whole collection.
     */
    #[Groups(['board:read'])]
    public function getTaskCount(): int
    {
        return $this->tasks->count();
    }

    /**
     * Count of completed tasks (those with a `completedOn` timestamp).
     * Criteria on an EXTRA_LAZY collection runs a filtered COUNT in SQL
     * without loading the rows.
     */
    #[Groups(['board:read'])]
    public function getCompletedTaskCount(): int
    {
        return $this->tasks
            ->matching(Criteria::create()->where(Criteria::expr()->neq('completedOn', null)))
            ->count();
    }

    /**
     * Convenience for security expressions: board access is determined
     * by the user's membership in the parent space (directly or via
     * group). Tolerates a transient null space for entities still being
     * constructed — falls through to "not accessible".
     */
    public function isAccessibleBy(?User $user): bool
    {
        return null !== $this->space && $this->space->hasMember($user);
    }

    /**
     * Convenience for security expressions: admin actions on a board
     * (e.g. delete, manage members) require the user to be an admin of
     * the parent space.
     */
    public function isSpaceAdmin(?User $user): bool
    {
        return null !== $this->space && $this->space->isAdmin($user);
    }

    /**
     * Deduplicated list of users with access to this board, derived
     * from the parent space's direct + group memberships. Used by code
     * that needs the full member universe (assignee picker, mention
     * parsing, attachment uploader gate).
     *
     * @return array<string, User>
     */
    public function getEffectiveMembers(): array
    {
        return null === $this->space ? [] : $this->space->getEffectiveUsers();
    }

    /**
     * Backward-compatible serialization shim (#185 → PR 4): the
     * existing PWA reads `board.members` to render member chips
     * and to check "is the user a member?". The underlying property
     * is gone (`board_member` was dropped) — this getter boards
     * the parent space's effective members onto a plain User[] so
     * the JSON-LD response continues to expose a `members` array.
     * Will be removed once PR 4 (#187) updates the PWA to read
     * membership directly from the space.
     *
     * @return list<User>
     */
    #[Groups(['board:read'])]
    public function getMembers(): array
    {
        return array_values($this->getEffectiveMembers());
    }
}
