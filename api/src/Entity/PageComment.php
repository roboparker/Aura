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
use App\Repository\PageCommentRepository;
use App\State\PageCommentAuthorProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Comment on a {@see Page} (#183). Mirrors the existing task-side
 * {@see TaskComment} shape (author, body, createdAt, updatedAt) but lives
 * in its own table — the task `TaskComment` row carries a hard-required
 * `task` FK that doesn't make sense for pages, and refactoring it to
 * be polymorphic would balloon the diff well past PR 4 of the spaces
 * stack.
 *
 * Access flows through the parent page's space (#185 matrix):
 *  - read/list/create: any space member
 *  - edit: comment author only
 *  - delete: comment author OR space admin
 *
 * Threading is intentionally flat for this PR — page discussions tend
 * to be lighter than task threads, and a flat list keeps the rendering
 * simple. A `parent` self-FK can land later if the demand shows up.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getPage() !== null and object.getPage().getSpace().hasMember(user)))",
            processor: PageCommentAuthorProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getPage().getSpace().hasMember(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user)",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user or object.getPage().getSpace().isAdmin(user))",
        ),
    ],
    normalizationContext: ['groups' => ['page_comment:read']],
    denormalizationContext: ['groups' => ['page_comment:write']],
    order: ['createdAt' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['page' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
#[ORM\Entity(repositoryClass: PageCommentRepository::class)]
#[ORM\Table(name: 'page_comment')]
#[ORM\Index(columns: ['page_id', 'created_at'], name: 'idx_page_comment_page_created')]
#[ORM\HasLifecycleCallbacks]
class PageComment
{
    public const MAX_BODY_LENGTH = 50_000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['page_comment:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Page is required.')]
    #[Groups(['page_comment:read', 'page_comment:write'])]
    private ?Page $page = null;

    /**
     * Stamped by {@see PageCommentAuthorProcessor} on POST; read-only
     * on the wire so a client can't post on someone else's behalf.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['page_comment:read'])]
    private ?User $author = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Body is required.')]
    #[Assert\Length(
        max: self::MAX_BODY_LENGTH,
        maxMessage: 'Body cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['page_comment:read', 'page_comment:write'])]
    private string $body = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['page_comment:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['page_comment:read'])]
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

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): static
    {
        $this->page = $page;
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
}
