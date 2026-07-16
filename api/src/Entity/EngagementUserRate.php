<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A per-person billable rate override on an {@see Engagement} (#653) —
 * Harvest's "person rate": when set, time this user tracks on the engagement
 * snapshots this rate instead of the category's. Written as part of the
 * engagement's `userRates` array (like categories), one row per user.
 */
#[ORM\Entity]
#[ORM\Table(name: 'engagement_user_rate')]
#[ORM\UniqueConstraint(name: 'uniq_engagement_user_rate', columns: ['engagement_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_engagement_user_rate_user')]
class EngagementUserRate
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['engagement:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Engagement::class, inversedBy: 'userRates')]
    #[ORM\JoinColumn(name: 'engagement_id', nullable: false, onDelete: 'CASCADE')]
    private ?Engagement $engagement = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'A user is required.')]
    #[Groups(['engagement:read', 'engagement:write'])]
    private ?User $user = null;

    /** Hourly billable rate in minor units of the engagement's currency. */
    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    #[Groups(['engagement:read', 'engagement:write'])]
    private int $rateAmount = 0;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

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
}
