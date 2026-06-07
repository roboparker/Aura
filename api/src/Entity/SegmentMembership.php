<?php

namespace App\Entity;

use App\Repository\SegmentMembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A manual override placing a single user explicitly in (or out of) a
 * {@see Segment}, regardless of role/rollout rules. `mode` is:
 *   - "include": force the user into the segment (e.g. a hand-picked beta)
 *   - "exclude": force the user out (a kill switch for one account that the
 *     rollout bucket or a role rule would otherwise include)
 *
 * Exclude wins over include wins over rules — see
 * {@see \App\Service\SegmentEvaluator}. Not a top-level API resource; read
 * via the embedded `memberships` collection on Segment and managed through
 * {@see \App\Controller\SegmentMemberController}.
 */
#[ORM\Entity(repositoryClass: SegmentMembershipRepository::class)]
#[ORM\Table(name: 'segment_membership')]
#[ORM\UniqueConstraint(name: 'uniq_segment_membership_user', columns: ['segment_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_segment_membership_user')]
class SegmentMembership
{
    public const MODE_INCLUDE = 'include';
    public const MODE_EXCLUDE = 'exclude';
    public const MODES = [self::MODE_INCLUDE, self::MODE_EXCLUDE];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['segment:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Segment::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'segment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Segment $segment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['segment:read'])]
    private ?User $user = null;

    #[ORM\Column(length: 16, options: ['default' => self::MODE_INCLUDE])]
    #[Groups(['segment:read'])]
    private string $mode = self::MODE_INCLUDE;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['segment:read'])]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSegment(): ?Segment
    {
        return $this->segment;
    }

    public function setSegment(?Segment $segment): static
    {
        $this->segment = $segment;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): static
    {
        $this->mode = $mode;
        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
