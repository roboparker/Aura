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
use App\Filter\PageSearchFilter;
use App\Repository\PageRepository;
use App\State\PageAuthorProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Long-form, hierarchical Notion-style document owned by a `Space`
 * (#183). Title + markdown body + optional parent page form a tree;
 * audit history flows through Gedmo `Loggable` like Project/Task.
 *
 * Access mirrors the parent space (#185 access matrix):
 *  - read/list/create: any space member
 *  - edit: page author OR space admin
 *  - delete: page author OR space admin (cascades to descendants via
 *    the self-FK `ON DELETE CASCADE`)
 *
 * Comments live in the unified {@see Comment} entity (#228) with
 * `commentable_type='page'`. Each page exposes its comments via the
 * `comments` inverse mapping below for cascade-delete and template
 * convenience; clients fetch the thread by querying
 * `GET /comments?page=/pages/<uuid>` ordered by `createdAt ASC`.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            // Space membership is checked here rather than relying on
            // `Get` because the client supplies the space IRI in the
            // payload — denormalisation has run by the time this fires.
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user)))",
            processor: PageAuthorProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().hasMember(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getCreatedBy() == user or object.getSpace().isAdmin(user))",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getCreatedBy() == user or object.getSpace().isAdmin(user))",
        ),
    ],
    normalizationContext: ['groups' => ['page:read']],
    denormalizationContext: ['groups' => ['page:write']],
    // Pages list defaults to "top-level first, by sort position then
    // newest first" so the default rendering of `/pages` builds the
    // tree top-down.
    order: ['parent' => 'ASC', 'position' => 'ASC', 'createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'space' => 'exact',
    'parent' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'updatedAt', 'position'])]
#[ApiFilter(PageSearchFilter::class)]
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'page')]
#[ORM\Index(columns: ['space_id'], name: 'idx_page_space')]
#[ORM\Index(columns: ['parent_id'], name: 'idx_page_parent')]
#[ORM\Index(columns: ['space_id', 'parent_id', 'position'], name: 'idx_page_space_tree')]
// Mirror the GIN index on `search_vector` from the Page migration so
// doctrine:schema:validate doesn't try to drop it on every CI run.
#[ORM\Index(columns: ['search_vector'], name: 'idx_page_search_vector', flags: ['gin'])]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\Loggable(logEntryClass: ActivityLog::class)]
class Page
{
    public const MAX_TITLE_LENGTH = 200;
    public const MAX_BODY_LENGTH = 200_000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['page:read'])]
    private ?Uuid $id = null;

    /**
     * Owning space — gates read/list via `PageAccessExtension` and
     * drives the security expressions for write/delete. Required.
     */
    #[ORM\ManyToOne(targetEntity: Space::class, inversedBy: 'pages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['page:read', 'page:write'])]
    private ?Space $space = null;

    /**
     * Optional parent page. Self-FK forms the tree; cascade-on-delete
     * means deleting a parent removes all descendants. The PR doesn't
     * enforce a max depth — the renderer collapses deep nests.
     */
    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['page:read', 'page:write'])]
    private ?Page $parent = null;

    /**
     * Stamped server-side by {@see PageAuthorProcessor} on create.
     * Kept on the read side so the PWA can surface "by Alice" in the
     * page list without a follow-up fetch. Read-only on the wire so
     * a client can't post on someone else's behalf.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['page:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(
        max: self::MAX_TITLE_LENGTH,
        maxMessage: 'Title cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['page:read', 'page:write'])]
    #[Gedmo\Versioned]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    #[Assert\Length(
        max: self::MAX_BODY_LENGTH,
        maxMessage: 'Body cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['page:read', 'page:write'])]
    #[Gedmo\Versioned]
    private string $body = '';

    /**
     * Postgres-managed full-text search vector (title + body),
     * populated by a STORED generated column — see the page migration.
     * Mapped here so DQL can reference `p.searchVector` from the
     * search filter; never written from PHP, never serialised in API
     * responses.
     */
    #[ORM\Column(name: 'search_vector', type: 'text', nullable: true, insertable: false, updatable: false)]
    private ?string $searchVector = null;

    /**
     * Sort key within the parent (or top-level if parent is null). Two
     * pages can share a position; ties break on `createdAt DESC`. The
     * client supplies it on create/edit; no auto-renumber.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Position cannot be negative.')]
    #[Groups(['page:read', 'page:write'])]
    private int $position = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['page:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['page:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Comment>
     */
    #[ORM\OneToMany(mappedBy: 'page', targetEntity: Comment::class, cascade: ['remove'])]
    private Collection $comments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->comments = new ArrayCollection();
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

    public function getSpace(): ?Space
    {
        return $this->space;
    }

    public function setSpace(?Space $space): static
    {
        $this->space = $space;
        return $this;
    }

    public function getParent(): ?Page
    {
        return $this->parent;
    }

    public function setParent(?Page $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
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

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, Comment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }
}
