<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\ProjectRepository;
use App\State\ProjectOwnerProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Shared container for tasks. Access is all-or-nothing: every member (the
 * owner included) can read, edit, add members to, and delete the project,
 * and can see/edit every task attached to it regardless of task ownership.
 * The `owner` field records who created the project for display/audit —
 * it does not grant extra privileges beyond what members already have.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: ProjectOwnerProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getMembers().contains(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getMembers().contains(user))",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getMembers().contains(user))",
        ),
    ],
    normalizationContext: ['groups' => ['project:read']],
    denormalizationContext: ['groups' => ['project:write']],
    order: ['createdOn' => 'DESC'],
)]
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_project_owner')]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['project:read', 'task:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['project:read'])]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(max: 255, maxMessage: 'Title cannot be longer than {{ limit }} characters.')]
    #[Groups(['project:read', 'project:write', 'task:read'])]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 100000, maxMessage: 'Description cannot be longer than {{ limit }} characters.')]
    #[Groups(['project:read', 'project:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['project:read'])]
    private \DateTimeImmutable $createdOn;

    /**
     * Members with full access to the project and its tasks. The creator is
     * added here automatically by ProjectOwnerProcessor; keeping owner inside
     * the set means access checks only need to inspect `members`.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'project_member')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['project:read', 'project:write'])]
    private Collection $members;

    /**
     * @var Collection<int, Task>
     */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Task::class)]
    private Collection $tasks;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        $this->members = new ArrayCollection();
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
     * @return Collection<int, User>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(User $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
        }
        return $this;
    }

    public function removeMember(User $member): static
    {
        $this->members->removeElement($member);
        return $this;
    }

    /**
     * @return Collection<int, Task>
     */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }
}
