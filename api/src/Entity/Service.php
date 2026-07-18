<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Repository\ServiceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A billable category on a {@see Project} (Harvest-style): a named kind of
 * work (e.g. "Design", "Development") with an hourly rate. Managed as an embedded
 * collection on {@see Project::$categories} — clients create/edit/delete
 * categories exclusively through `POST /projects` and
 * `PATCH /projects/{id}`. The lone read-only `Get` operation exists so a
 * {@see TimeEntry}'s `category` IRI resolves (it points at one category, which
 * fixes its rate).
 */
#[ApiResource(
    shortName: 'Service',
    operations: [
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getProject().getSpace().hasMember(user))",
        ),
    ],
    normalizationContext: ['groups' => ['project:read']],
)]
#[ORM\Entity(repositoryClass: ServiceRepository::class)]
#[ORM\Table(name: 'service')]
#[ORM\Index(columns: ['project_id'], name: 'idx_service_project')]
class Service
{
    public const MAX_NAME_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['project:read', 'time_entry:read'])]
    private ?Uuid $id = null;

    /** Owning board. Set by {@see Project::addCategory} — never from the payload. */
    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'A category name is required.')]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups(['project:read', 'project:write', 'time_entry:read'])]
    private string $name = '';

    /** Hourly rate in minor units (e.g. cents) of the board's currency. */
    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero(message: 'A rate cannot be negative.')]
    #[Groups(['project:read', 'project:write', 'time_entry:read'])]
    private int $billingRate = 0;

    #[ORM\Column(type: 'integer')]
    #[Groups(['project:read', 'project:write'])]
    private int $position = 0;

    /**
     * Whether time tracked against this category is billable. Billability lives
     * on the category (not the individual entry): a {@see TimeEntry} snapshots
     * this onto itself on save, so invoices pull the right pool of time.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['project:read', 'project:write', 'time_entry:read'])]
    private bool $billable = true;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;

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

    public function getBillingRate(): int
    {
        return $this->billingRate;
    }

    public function setBillingRate(int $billingRate): self
    {
        $this->billingRate = $billingRate;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function isBillable(): bool
    {
        return $this->billable;
    }

    public function setBillable(bool $billable): self
    {
        $this->billable = $billable;

        return $this;
    }
}
