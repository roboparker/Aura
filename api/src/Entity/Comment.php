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
use App\Repository\CommentRepository;
use App\State\CommentAuthorProcessor;
use App\State\CommentDeleteProcessor;
use App\State\CommentUpdateProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Comment on a task. Read access mirrors the parent task — anyone who can
 * see the task (owner, project member, admin) can see its comments. Write
 * access is narrower: any reader can post; only the comment author can
 * edit; the author OR the task owner OR an admin can delete (so a project
 * lead can clean up after a teammate without needing to escalate).
 *
 * Threading is intentionally flat in v1 — see #105 for follow-up.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getTask().getOwner() == user or (object.getTask().getProject() !== null and object.getTask().getProject().getMembers().contains(user)))",
            processor: CommentAuthorProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getTask().getOwner() == user or (object.getTask().getProject() !== null and object.getTask().getProject().getMembers().contains(user)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user)",
            processor: CommentUpdateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getAuthor() == user or object.getTask().getOwner() == user)",
            processor: CommentDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['comment:read']],
    denormalizationContext: ['groups' => ['comment:write']],
    order: ['createdAt' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['task' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'], arguments: ['orderParameterName' => 'order'])]
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(columns: ['task_id', 'created_at'], name: 'idx_comment_task_created')]
#[ORM\HasLifecycleCallbacks]
class Comment
{
    public const MAX_BODY_LENGTH = 10_000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['comment:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Task is required.')]
    #[Groups(['comment:read', 'comment:write'])]
    private ?Task $task = null;

    /**
     * Author is set server-side by CommentAuthorProcessor on POST; the
     * setter exists so PATCH can no-op the field, but the serializer
     * group keeps it read-only over the wire.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['comment:read'])]
    private ?User $author = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Comment body is required.')]
    #[Assert\Length(
        max: self::MAX_BODY_LENGTH,
        maxMessage: 'Comment cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['comment:read', 'comment:write'])]
    private string $body = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['comment:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Bumped automatically on PATCH via the lifecycle hook; lets the PWA
     * render an "(edited)" affordance without diffing payloads.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['comment:read'])]
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

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): static
    {
        $this->task = $task;
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
