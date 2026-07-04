<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A user's membership in an {@see Organization} (#billing Phase 1), carrying
 * their org-level role. Unique per (organization, user). Non-`guest` roles
 * consume a paid seat.
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_membership')]
#[ORM\UniqueConstraint(name: 'uniq_organization_membership_user', columns: ['organization_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_organization_membership_user')]
class OrganizationMembership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['organization:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['organization:read'])]
    private ?User $user = null;

    #[ORM\Column(length: 16, options: ['default' => Organization::ROLE_MEMBER])]
    #[Assert\Choice(choices: Organization::ALLOWED_ROLES, message: 'Invalid organization role.')]
    #[Groups(['organization:read'])]
    private string $role = Organization::ROLE_MEMBER;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['organization:read'])]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;
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
}
