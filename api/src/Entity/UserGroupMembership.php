<?php

namespace App\Entity;

use App\Repository\UserGroupMembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Direct user membership in a `UserGroup`. Replaces the old plain
 * `user_group_member` ManyToMany join table so each membership can carry
 * its own `joinedAt` timestamp — the group detail page renders a
 * "Joined" column per member.
 *
 * There is intentionally no `role` column: a group has exactly one
 * controller (the `UserGroup.owner` FK) and everyone else is a plain
 * member, so "owner vs member" is derived by comparing the membership's
 * user against the group's owner rather than denormalised here (which
 * would introduce a sync invariant on every ownership transfer).
 *
 * Not exposed as a top-level API resource — read-only via the embedded
 * `memberships` collection on UserGroup; managed through
 * UserGroupMemberController.
 */
#[ORM\Entity(repositoryClass: UserGroupMembershipRepository::class)]
#[ORM\Table(name: 'user_group_membership')]
#[ORM\UniqueConstraint(name: 'uniq_user_group_membership_user', columns: ['user_group_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_user_group_membership_user')]
class UserGroupMembership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['group:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: UserGroup::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'user_group_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?UserGroup $group = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['group:read'])]
    private ?User $user = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['group:read'])]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getGroup(): ?UserGroup
    {
        return $this->group;
    }

    public function setGroup(?UserGroup $group): static
    {
        $this->group = $group;
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

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /**
     * Backfill / migration escape hatch so historical join rows keep a
     * meaningful "joined" date (the group's creation time) instead of
     * the row-insert time. Not exposed on the wire.
     */
    public function setJoinedAt(\DateTimeImmutable $joinedAt): static
    {
        $this->joinedAt = $joinedAt;
        return $this;
    }
}
