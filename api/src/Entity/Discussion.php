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
use App\Filter\DiscussionSearchFilter;
use App\Repository\DiscussionRepository;
use App\State\DiscussionAuthorProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Project-level discussion thread (#91). Read access mirrors the
 * project — every member can browse; create access is also any
 * member; edit is author-only; delete + pin/lock are project-owner OR
 * the post's author. Pin/lock are exposed as plain PATCHable fields
 * (gated by {@see DiscussionUpdateProcessor} in a follow-up — for the
 * MVP they're owner-only via the entity-level expression).
 *
 * Replies, locking interactions, and the chat side of the original
 * spec land in follow-ups — this PR ships only the Discussion entity
 * and its CRUD so the project tabs UI can be built first.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getProject() !== null and object.getProject().getMembers().contains(user)))",
            processor: DiscussionAuthorProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getProject().getMembers().contains(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user or object.getProject().getOwner() == user)",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user or object.getProject().getOwner() == user)",
        ),
    ],
    normalizationContext: ['groups' => ['discussion:read']],
    denormalizationContext: ['groups' => ['discussion:write']],
    order: ['isPinned' => 'DESC', 'createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact', 'category' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'updatedAt'])]
#[ApiFilter(DiscussionSearchFilter::class)]
#[ORM\Entity(repositoryClass: DiscussionRepository::class)]
#[ORM\Table(name: 'discussion')]
#[ORM\Index(columns: ['project_id', 'created_at'], name: 'idx_discussion_project_created')]
#[ORM\Index(columns: ['project_id', 'is_pinned', 'created_at'], name: 'idx_discussion_project_pinned')]
// Mirror the GIN index on `search_vector` from Version20260506010000 so
// doctrine:schema:validate doesn't try to drop it on every CI run.
#[ORM\Index(columns: ['search_vector'], name: 'idx_discussion_search_vector', flags: ['gin'])]
#[ORM\HasLifecycleCallbacks]
class Discussion
{
    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_IDEAS = 'ideas';
    public const CATEGORY_ANNOUNCEMENTS = 'announcements';
    public const CATEGORY_QA = 'q-and-a';

    public const ALLOWED_CATEGORIES = [
        self::CATEGORY_GENERAL,
        self::CATEGORY_IDEAS,
        self::CATEGORY_ANNOUNCEMENTS,
        self::CATEGORY_QA,
    ];

    public const MAX_TITLE_LENGTH = 200;
    public const MAX_BODY_LENGTH = 50_000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['discussion:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Project is required.')]
    #[Groups(['discussion:read', 'discussion:write'])]
    private ?Project $project = null;

    /**
     * Stamped by DiscussionAuthorProcessor on POST; read-only on the
     * wire so a client can't post on someone else's behalf.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['discussion:read'])]
    private ?User $author = null;

    #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(
        max: self::MAX_TITLE_LENGTH,
        maxMessage: 'Title cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['discussion:read', 'discussion:write'])]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Body is required.')]
    #[Assert\Length(
        max: self::MAX_BODY_LENGTH,
        maxMessage: 'Body cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['discussion:read', 'discussion:write'])]
    private string $body = '';

    /**
     * Postgres-managed full-text search vector (title + body),
     * populated by a STORED generated column — see Version20260506010000.
     * Mapped here so DQL can reference `d.searchVector` in the search
     * filter; never written from PHP, never serialised in API responses.
     */
    #[ORM\Column(name: 'search_vector', type: 'text', nullable: true, insertable: false, updatable: false)]
    private ?string $searchVector = null;

    #[ORM\Column(length: 16)]
    #[Assert\Choice(
        choices: self::ALLOWED_CATEGORIES,
        message: 'Category must be one of: general, ideas, announcements, q-and-a.',
    )]
    #[Groups(['discussion:read', 'discussion:write'])]
    private string $category = self::CATEGORY_GENERAL;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['discussion:read', 'discussion:write'])]
    private bool $isPinned = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['discussion:read', 'discussion:write'])]
    private bool $isLocked = false;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['discussion:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['discussion:read'])]
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

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    /**
     * Symfony's PropertyInfoExtractor derives the property name from the
     * getter — `isPinned()` would strip the `is` prefix and yield `pinned`,
     * which doesn't match the writable name `isPinned` (from `setIsPinned`).
     * The mismatch makes the Serializer drop the field on read. Naming the
     * read accessor `getIsPinned` keeps both ends aligned on `isPinned`.
     */
    public function getIsPinned(): bool
    {
        return $this->isPinned;
    }

    public function isPinned(): bool
    {
        return $this->isPinned;
    }

    public function setIsPinned(bool $isPinned): static
    {
        $this->isPinned = $isPinned;
        return $this;
    }

    public function getIsLocked(): bool
    {
        return $this->isLocked;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    public function setIsLocked(bool $isLocked): static
    {
        $this->isLocked = $isLocked;
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
}
