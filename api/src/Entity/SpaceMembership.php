<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Direct user membership in a `Space` (#181). Carries the role
 * (`admin` | `member`) the user holds in that space.
 *
 * Not exposed as a top-level API resource in PR 1 — read-only via the
 * embedded `userMemberships` collection on Space. PR 3 (#186) adds
 * the management endpoints (`POST /spaces/{id}/members`, etc.) and
 * the invite-by-email flow.
 */
#[ORM\Entity]
#[ORM\Table(name: 'space_membership')]
#[ORM\UniqueConstraint(name: 'uniq_space_membership_user', columns: ['space_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_space_membership_user')]
class SpaceMembership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['space:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class, inversedBy: 'userMemberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Space $space = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['space:read'])]
    private ?User $user = null;

    #[ORM\Column(length: 16, options: ['default' => Space::ROLE_MEMBER])]
    #[Assert\Choice(
        choices: Space::ALLOWED_ROLES,
        message: 'Role must be one of: admin, member.',
    )]
    #[Groups(['space:read'])]
    private string $role = Space::ROLE_MEMBER;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['space:read'])]
    private \DateTimeImmutable $joinedAt;

    /**
     * Internal cost rate (#653): what an hour of this member costs the team,
     * in minor units — the profitability reports subtract it from billable
     * amounts. Admin-set via POST /spaces/{id}/members/{userId}/cost-rate;
     * space-level so it follows the person across engagements.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['space:read'])]
    private ?int $costRateAmount = null;

    /**
     * Custom roles assigned to this member (#space-roles). Effective
     * permissions are the union of these; an empty set = unrestricted
     * (back-compat). Admins are never restricted regardless.
     *
     * @var Collection<int, SpaceRole>
     */
    #[ORM\ManyToMany(targetEntity: SpaceRole::class)]
    #[ORM\JoinTable(name: 'space_membership_role')]
    #[Groups(['space:read'])]
    private Collection $roles;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
        $this->roles = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getCostRateAmount(): ?int
    {
        return $this->costRateAmount;
    }

    public function setCostRateAmount(?int $costRateAmount): static
    {
        $this->costRateAmount = $costRateAmount;
        return $this;
    }

    /**
     * @return Collection<int, SpaceRole>
     */
    public function getRoles(): Collection
    {
        return $this->roles;
    }

    public function addRole(SpaceRole $role): static
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
        }

        return $this;
    }

    public function removeRole(SpaceRole $role): static
    {
        $this->roles->removeElement($role);

        return $this;
    }

    public function clearRoles(): static
    {
        $this->roles->clear();

        return $this;
    }
}
