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
use App\Validator\ValidProjectAttachments;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
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
// Mirror the GIN index on `search_vector` from Version20260506010000 so
// doctrine:schema:validate doesn't try to drop it on every CI run.
#[ORM\Index(columns: ['search_vector'], name: 'idx_project_search_vector', flags: ['gin'])]
#[ApiFilter(ProjectSearchFilter::class)]
#[Gedmo\Loggable(logEntryClass: ActivityLog::class)]
#[ValidProjectAttachments]
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

    /**
     * MediaObjects attached at the project level (cover docs, shared specs).
     * Mirrors Task::$attachments. Membership is edited via PATCH on the
     * Project with an `attachments` array of MediaObject IRIs; the PWA
     * uploads via `POST /media-objects` (kind=attachment) first to obtain
     * the IRI. {@see ValidProjectAttachments} enforces that uploaders are
     * project members and that the kind is correct.
     *
     * @var Collection<int, MediaObject>
     */
    #[ORM\ManyToMany(targetEntity: MediaObject::class)]
    #[ORM\JoinTable(name: 'project_attachment')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'media_object_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['project:read', 'project:write'])]
    private Collection $attachments;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        $this->members = new ArrayCollection();
        $this->tasks = new ArrayCollection();
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
