<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Repository\BillingCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A billable category on a {@see BillingProject} (Harvest-style): a named kind of
 * work (e.g. "Design", "Development") with an hourly rate. Managed as an embedded
 * collection on {@see BillingProject::$categories} — clients create/edit/delete
 * categories exclusively through `POST /billing_projects` and
 * `PATCH /billing_projects/{id}`. The lone read-only `Get` operation exists so a
 * {@see TimeEntry}'s `category` IRI resolves (it points at one category, which
 * fixes its rate).
 */
#[ApiResource(
    shortName: 'BillingCategory',
    operations: [
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getBillingProject().getSpace().hasMember(user))",
        ),
    ],
    normalizationContext: ['groups' => ['billing_project:read']],
)]
#[ORM\Entity(repositoryClass: BillingCategoryRepository::class)]
#[ORM\Table(name: 'billing_category')]
#[ORM\Index(columns: ['billing_project_id'], name: 'idx_billing_category_project')]
class BillingCategory
{
    public const MAX_NAME_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['billing_project:read', 'time_entry:read'])]
    private ?Uuid $id = null;

    /** Owning board. Set by {@see BillingProject::addCategory} — never from the payload. */
    #[ORM\ManyToOne(targetEntity: BillingProject::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BillingProject $billingProject = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'A category name is required.')]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups(['billing_project:read', 'billing_project:write', 'time_entry:read'])]
    private string $name = '';

    /** Hourly rate in minor units (e.g. cents) of the board's currency. */
    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero(message: 'A rate cannot be negative.')]
    #[Groups(['billing_project:read', 'billing_project:write', 'time_entry:read'])]
    private int $rateAmount = 0;

    #[ORM\Column(type: 'integer')]
    #[Groups(['billing_project:read', 'billing_project:write'])]
    private int $position = 0;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getBillingProject(): ?BillingProject
    {
        return $this->billingProject;
    }

    public function setBillingProject(?BillingProject $billingProject): self
    {
        $this->billingProject = $billingProject;

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

    public function getRateAmount(): int
    {
        return $this->rateAmount;
    }

    public function setRateAmount(int $rateAmount): self
    {
        $this->rateAmount = $rateAmount;

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
}
