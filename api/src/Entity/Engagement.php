<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\EngagementRepository;
use App\State\EngagementCreatorProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A billable board (Harvest-style): the unit that time is tracked against and
 * invoiced from. It belongs to a {@see Client} and defines a set of
 * {@see EngagementCategory} rows (named work + hourly rate). Time entries select a
 * engagement + one of its categories, which fixes the rate. Task-management
 * {@see Board}s can be assigned to a engagement (their `engagement` FK).
 *
 * Space-scoped and admin-managed like {@see Client}: the `invoices` permission
 * category gates create/read/update/delete via {@see \App\Doctrine\EngagementAccessExtension}.
 * Members select from it through the minimal picker
 * ({@see \App\Controller\EngagementOptionsController}).
 */
#[ApiResource(
    shortName: 'Engagement',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user) and is_granted('space.invoices.create', object)))",
            processor: EngagementCreatorProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.invoices.read', object)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.invoices.update', object)))",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.invoices.delete', object)))",
        ),
    ],
    normalizationContext: ['groups' => ['engagement:read']],
    denormalizationContext: ['groups' => ['engagement:write']],
    order: ['name' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact', 'client' => 'exact', 'name' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['archived'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
#[ORM\Entity(repositoryClass: EngagementRepository::class)]
#[ORM\Table(name: 'engagement')]
#[ORM\Index(columns: ['space_id', 'name'], name: 'idx_engagement_space_name')]
#[ORM\Index(columns: ['client_id'], name: 'idx_engagement_client')]
#[ORM\HasLifecycleCallbacks]
#[Assert\Callback('validateConsistency')]
class Engagement
{
    public const MAX_NAME_LENGTH = 200;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['engagement:read', 'time_entry:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['engagement:read', 'engagement:write'])]
    private ?Space $space = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'A client is required.')]
    #[Groups(['engagement:read', 'engagement:write', 'time_entry:read'])]
    private ?Client $client = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'A board name is required.')]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups(['engagement:read', 'engagement:write', 'time_entry:read'])]
    private string $name = '';

    /** ISO 4217 currency for every category rate; defaults to the client's on create. */
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    #[Assert\Currency]
    #[Groups(['engagement:read', 'engagement:write', 'time_entry:read'])]
    private ?string $currency = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['engagement:read', 'engagement:write'])]
    private bool $archived = false;

    /**
     * @var Collection<int, EngagementCategory>
     */
    #[ORM\OneToMany(
        mappedBy: 'engagement',
        targetEntity: EngagementCategory::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    #[Groups(['engagement:read', 'engagement:write'])]
    private Collection $categories;

    /**
     * Task-management boards assigned to this engagement (inverse of
     * {@see Board::$engagement}). Read-only summary for the detail page.
     *
     * @var Collection<int, Board>
     */
    #[ORM\OneToMany(mappedBy: 'engagement', targetEntity: Board::class)]
    private Collection $assignedProjects;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['engagement:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['engagement:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['engagement:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->assignedProjects = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function validateConsistency(ExecutionContextInterface $context): void
    {
        if (
            null !== $this->client && null !== $this->space
            && true !== $this->client->getSpace()?->getId()?->equals($this->space->getId())
        ) {
            $context->buildViolation('Client must belong to the same space.')
                ->atPath('client')
                ->addViolation();
        }

        $seen = [];
        foreach ($this->categories as $i => $category) {
            $key = mb_strtolower(trim($category->getName()));
            if ('' === $key) {
                continue;
            }
            if (isset($seen[$key])) {
                $context->buildViolation('Category names must be unique.')
                    ->atPath(sprintf('categories[%d].name', $i))
                    ->addViolation();
            }
            $seen[$key] = true;
        }
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSpace(): ?Space
    {
        return $this->space;
    }

    public function setSpace(?Space $space): self
    {
        $this->space = $space;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): self
    {
        $this->archived = $archived;

        return $this;
    }

    /**
     * @return Collection<int, EngagementCategory>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(EngagementCategory $category): self
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $category->setEngagement($this);
        }

        return $this;
    }

    public function removeCategory(EngagementCategory $category): self
    {
        $this->categories->removeElement($category);

        return $this;
    }

    /**
     * @return Collection<int, Board>
     */
    public function getAssignedProjects(): Collection
    {
        return $this->assignedProjects;
    }

    /**
     * Flattened task-board roster for the detail page (avoids embedding the
     * full Board resource + a serialization group on it).
     *
     * @return list<array{id: string, title: string}>
     */
    #[Groups(['engagement:read'])]
    public function getAssignedProjectList(): array
    {
        $out = [];
        foreach ($this->assignedProjects as $board) {
            $out[] = ['id' => (string) $board->getId(), 'title' => $board->getTitle()];
        }

        return $out;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

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
