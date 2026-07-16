<?php

namespace App\Entity;

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
use App\Repository\ClientRepository;
use App\State\ClientCreatorProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A billing party a space invoices (#445) — the person or company on the "bill
 * to" of an {@see Invoice}. A deliberately minimal slice of a future CRM: name,
 * contact, a default billing currency and hourly rate that seed new invoices.
 *
 * Space-scoped and gated on the admin-reserved `invoices` permission category,
 * so only space admins (or a member granted an invoicing role) manage clients.
 */
#[ApiResource(
    shortName: 'Client',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user) and is_granted('space.invoices.create', object)))",
            processor: ClientCreatorProcessor::class,
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
    normalizationContext: ['groups' => ['client:read']],
    denormalizationContext: ['groups' => ['client:write']],
    order: ['name' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact', 'name' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'client')]
#[ORM\Index(columns: ['space_id', 'name'], name: 'idx_client_space_name')]
#[ORM\HasLifecycleCallbacks]
class Client
{
    public const MAX_NAME_LENGTH = 200;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['client:read', 'invoice:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['client:read', 'client:write'])]
    private ?Space $space = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'A client name is required.')]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups(['client:read', 'client:write', 'invoice:read'])]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    #[Groups(['client:read', 'client:write'])]
    private ?string $email = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $address = null;

    /** Default billing currency (ISO 4217); seeds new invoices for this client. */
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    #[Assert\Currency]
    #[Groups(['client:read', 'client:write'])]
    private ?string $currency = null;

    /** Default hourly rate in minor units of {@see $currency}. */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['client:read', 'client:write'])]
    private ?int $defaultRateAmount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $notes = null;

    /**
     * sha256 of the client-portal token (#674) — the plaintext only ever
     * leaves in the link/email minted by POST /clients/{id}/portal-link.
     * Rotating invalidates the previous link. Never serialized.
     */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $portalToken = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['client:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['client:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['client:read'])]
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

    public function getPortalToken(): ?string
    {
        return $this->portalToken;
    }

    public function setPortalToken(?string $portalToken): self
    {
        $this->portalToken = $portalToken;

        return $this;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

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

    public function getDefaultRateAmount(): ?int
    {
        return $this->defaultRateAmount;
    }

    public function setDefaultRateAmount(?int $defaultRateAmount): self
    {
        $this->defaultRateAmount = $defaultRateAmount;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
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
