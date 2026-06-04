<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Filter\ProjectSearchFilter;
use App\Repository\ProjectRepository;
use App\State\ProjectOwnerProcessor;
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
 * project and its tasks. The `owner` field records who created the
 * project for display/audit — it does not grant extra privileges
 * beyond what space membership already provides.
 *
 * Per #185:
 *  - read/list/create: any space member
 *  - edit: any space member (project metadata is shared state)
 *  - delete: project creator (owner) or space admin
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
            // know about spaces yet — can keep posting projects without
            // an explicit IRI; ProjectOwnerProcessor will default it to
            // the caller's personal space (where they're admin) before
            // persist. When the client DOES pick a space, they must be a
            // member of it.
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace() === null or object.isAccessibleBy(user))",
            securityPostDenormalizeMessage: 'You can only create projects in a space you belong to.',
            processor: ProjectOwnerProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAccessibleBy(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAccessibleBy(user))",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user or object.isSpaceAdmin(user))",
            securityMessage: 'Only the project creator or a space admin can delete a project.',
        ),
    ],
    normalizationContext: ['groups' => ['project:read']],
    denormalizationContext: ['groups' => ['project:write']],
    order: ['createdOn' => 'DESC'],
)]
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_project_owner')]
#[ORM\Index(columns: ['space_id'], name: 'idx_project_space')]
// Mirror the GIN index on `search_vector` from Version20260506010000 so
// doctrine:schema:validate doesn't try to drop it on every CI run.
#[ORM\Index(columns: ['search_vector'], name: 'idx_project_search_vector', flags: ['gin'])]
#[ApiFilter(ProjectSearchFilter::class)]
#[ApiFilter(\ApiPlatform\Doctrine\Orm\Filter\SearchFilter::class, properties: ['space' => 'exact'])]
#[Gedmo\Loggable(logEntryClass: ActivityLog::class)]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['project:read', 'task:read', 'discussion:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['project:read'])]
    private ?User $owner = null;

    /**
     * The space this project lives in (#181). Set by ProjectOwnerProcessor
     * to the creator's personal space when the client doesn't specify
     * one — keeps the existing PWA, which is unaware of spaces, working
     * unchanged. Made non-null at the DB level once the migration's
     * backfill populates every existing row.
     *
     * Owner-based access is still in force in PR 1; PR 2 (#185) swaps
     * the access predicates to scope by space membership.
     */
    #[ORM\ManyToOne(targetEntity: Space::class, inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['project:read', 'project:write'])]
    private ?Space $space = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(max: 255, maxMessage: 'Title cannot be longer than {{ limit }} characters.')]
    #[Groups(['project:read', 'project:write', 'task:read', 'discussion:read'])]
    #[Gedmo\Versioned]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 100000, maxMessage: 'Description cannot be longer than {{ limit }} characters.')]
    #[Groups(['project:read', 'project:write'])]
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
    #[Groups(['project:read'])]
    private \DateTimeImmutable $createdOn;

    /**
     * @var Collection<int, Task>
     */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Task::class, fetch: 'EXTRA_LAZY')]
    private Collection $tasks;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        $this->tasks = new ArrayCollection();
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
     * Total task count, surfaced on the wire for list/dashboard rows.
     * EXTRA_LAZY makes this a COUNT query rather than hydrating the
     * whole collection.
     */
    #[Groups(['project:read'])]
    public function getTaskCount(): int
    {
        return $this->tasks->count();
    }

    /**
     * Count of completed tasks (those with a `completedOn` timestamp).
     * Criteria on an EXTRA_LAZY collection runs a filtered COUNT in SQL
     * without loading the rows.
     */
    #[Groups(['project:read'])]
    public function getCompletedTaskCount(): int
    {
        return $this->tasks
            ->matching(Criteria::create()->where(Criteria::expr()->neq('completedOn', null)))
            ->count();
    }

    /**
     * Convenience for security expressions: project access is determined
     * by the user's membership in the parent space (directly or via
     * group). Tolerates a transient null space for entities still being
     * constructed — falls through to "not accessible".
     */
    public function isAccessibleBy(?User $user): bool
    {
        return null !== $this->space && $this->space->hasMember($user);
    }

    /**
     * Convenience for security expressions: admin actions on a project
     * (e.g. delete, manage members) require the user to be an admin of
     * the parent space.
     */
    public function isSpaceAdmin(?User $user): bool
    {
        return null !== $this->space && $this->space->isAdmin($user);
    }

    /**
     * Deduplicated list of users with access to this project, derived
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
     * existing PWA reads `project.members` to render member chips
     * and to check "is the user a member?". The underlying property
     * is gone (`project_member` was dropped) — this getter projects
     * the parent space's effective members onto a plain User[] so
     * the JSON-LD response continues to expose a `members` array.
     * Will be removed once PR 4 (#187) updates the PWA to read
     * membership directly from the space.
     *
     * @return list<User>
     */
    #[Groups(['project:read'])]
    public function getMembers(): array
    {
        return array_values($this->getEffectiveMembers());
    }
}
