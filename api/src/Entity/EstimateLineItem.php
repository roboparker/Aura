<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line on an {@see Estimate} (#652) — the quote-side twin of
 * {@see InvoiceLineItem}: written as part of the estimate's `lineItems`
 * array, with {@see $amount} derived server-side by EstimateProcessor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estimate_line_item')]
#[ORM\Index(columns: ['estimate_id', 'position'], name: 'idx_estimate_line_item_estimate_position')]
class EstimateLineItem
{
    public const MAX_DESCRIPTION_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['estimate:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Estimate::class, inversedBy: 'lineItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Estimate $estimate = null;

    #[ORM\Column(length: self::MAX_DESCRIPTION_LENGTH)]
    #[Assert\NotBlank(message: 'A line description is required.')]
    #[Assert\Length(max: self::MAX_DESCRIPTION_LENGTH)]
    #[Groups(['estimate:read', 'estimate:write'])]
    private string $description = '';

    /** Hours or units (may be fractional). */
    #[ORM\Column(type: 'float')]
    #[Assert\PositiveOrZero]
    #[Groups(['estimate:read', 'estimate:write'])]
    private float $quantity = 1.0;

    /** Price per unit/hour in minor units of the estimate's currency. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['estimate:read', 'estimate:write'])]
    private int $unitAmount = 0;

    /** Derived: round(quantity × unitAmount), in minor units. Read-only. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['estimate:read'])]
    private int $amount = 0;

    #[ORM\Column(type: 'integer')]
    #[Groups(['estimate:read', 'estimate:write'])]
    private int $position = 0;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEstimate(): ?Estimate
    {
        return $this->estimate;
    }

    public function setEstimate(?Estimate $estimate): self
    {
        $this->estimate = $estimate;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function setQuantity(float $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitAmount(): int
    {
        return $this->unitAmount;
    }

    public function setUnitAmount(int $unitAmount): self
    {
        $this->unitAmount = $unitAmount;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

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
