<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\TaskRepository;
use App\State\TaskOwnerProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
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
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user or (object.getProject() !== null and object.getProject().getMembers().contains(user)))",
        ),
    ],
    normalizationContext: ['groups' => ['task:read']],
    denormalizationContext: ['groups' => ['task:write']],
    order: ['position' => 'ASC', 'createdOn' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact'])]
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_task_owner')]
#[ORM\Index(columns: ['owner_id', 'position'], name: 'idx_task_owner_position')]
#[ORM\Index(columns: ['project_id'], name: 'idx_task_project')]
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
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 100000, maxMessage: 'Description cannot be longer than {{ limit }} characters.')]
    #[Groups(['task:read', 'task:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['task:read'])]
    private \DateTimeImmutable $createdOn;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['task:read', 'task:write'])]
    private ?\DateTimeImmutable $completedOn = null;

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

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        $this->tags = new ArrayCollection();
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
}
