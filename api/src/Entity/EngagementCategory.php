<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Repository\EngagementCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A billable category on a {@see Engagement} (Harvest-style): a named kind of
 * work (e.g. "Design", "Development") with an hourly rate. Managed as an embedded
 * collection on {@see Engagement::$categories} — clients create/edit/delete
 * categories exclusively through `POST /engagements` and
 * `PATCH /engagements/{id}`. The lone read-only `Get` operation exists so a
 * {@see TimeEntry}'s `category` IRI resolves (it points at one category, which
 * fixes its rate).
 */
#[ApiResource(
    shortName: 'EngagementCategory',
    operations: [
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getEngagement().getSpace().hasMember(user))",
        ),
    ],
    normalizationContext: ['groups' => ['engagement:read']],
)]
#[ORM\Entity(repositoryClass: EngagementCategoryRepository::class)]
#[ORM\Table(name: 'engagement_category')]
#[ORM\Index(columns: ['engagement_id'], name: 'idx_engagement_category_project')]
class EngagementCategory
{
    public const MAX_NAME_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['engagement:read', 'time_entry:read'])]
    private ?Uuid $id = null;

    /** Owning board. Set by {@see Engagement::addCategory} — never from the payload. */
    #[ORM\ManyToOne(targetEntity: Engagement::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Engagement $engagement = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'A category name is required.')]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups(['engagement:read', 'engagement:write', 'time_entry:read'])]
    private string $name = '';

    /** Hourly rate in minor units (e.g. cents) of the board's currency. */
    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero(message: 'A rate cannot be negative.')]
    #[Groups(['engagement:read', 'engagement:write', 'time_entry:read'])]
    private int $rateAmount = 0;

    #[ORM\Column(type: 'integer')]
    #[Groups(['engagement:read', 'engagement:write'])]
    private int $position = 0;

    /**
     * Whether time tracked against this category is billable. Billability lives
     * on the category (not the individual entry): a {@see TimeEntry} snapshots
     * this onto itself on save, so invoices pull the right pool of time.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['engagement:read', 'engagement:write', 'time_entry:read'])]
    private bool $billable = true;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEngagement(): ?Engagement
    {
        return $this->engagement;
    }

    public function setEngagement(?Engagement $engagement): self
    {
        $this->engagement = $engagement;

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
