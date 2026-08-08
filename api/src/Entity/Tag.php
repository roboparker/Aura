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
use App\Repository\TagRepository;
use App\Service\AvatarColorService;
use App\State\TagOwnerProcessor;
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
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user) and is_granted('space.tags.create', object)))",
            processor: TagOwnerProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.tags.read', object)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.tags.update', object)))",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.tags.delete', object)))",
        ),
    ],
    normalizationContext: ['groups' => ['tag:read']],
    denormalizationContext: ['groups' => ['tag:write']],
    order: ['title' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact'])]
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_tag_owner')]
#[ORM\Index(columns: ['space_id'], name: 'idx_tag_space')]
class Tag
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['tag:read', 'task:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['tag:read'])]
    private ?User $owner = null;

    /**
     * Tags are scoped to a Space (#tags): every member of the space shares the
     * same tag pool. Set on create from the request; required.
     */
    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['tag:read', 'tag:write'])]
    private ?Space $space = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(max: 100, maxMessage: 'Title cannot be longer than {{ limit }} characters.')]
    #[Groups(['tag:read', 'tag:write', 'task:read'])]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 100000, maxMessage: 'Description cannot be longer than {{ limit }} characters.')]
    #[Groups(['tag:read', 'tag:write'])]
    private ?string $description = null;

    /**
     * Hex color for badge rendering. Stored as lowercase `#RRGGBB` so the
     * PWA can drop it straight into an inline style.
     *
     * Constrained to the same WCAG-AA palette as user avatars, spaces and
     * groups. Before this, any of the 16.7M hex values passed the regex, and
     * chips render their label over this colour — seeded tags reached only
     * 2.15:1. The PWA picker already offered just the palette; the API is
     * what accepted anything.
     */
    #[ORM\Column(length: 7)]
    #[Assert\NotBlank(message: 'Color is required.')]
    #[Assert\Choice(
        choices: AvatarColorService::PALETTE,
        message: 'Color must be one of the supported avatar palette values.',
    )]
    #[Groups(['tag:read', 'tag:write', 'task:read'])]
    private string $color = '#334155';

    /**
     * Inverse side of the Task↔Tag relation. The Task entity owns the join
     * table, so changes to membership are made by editing Task.tags.
     *
     * @var Collection<int, Task>
     */
    #[ORM\ManyToMany(targetEntity: Task::class, mappedBy: 'tags')]
    private Collection $tasks;

    public function __construct()
    {
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

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = strtolower($color);
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
