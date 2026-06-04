<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adds every member of a `UserGroup` to a `Space` transitively (#181).
 * Stored as a separate table from `SpaceMembership` so the cardinality
 * is a small, easily-iterable list of groups attached to the space —
 * effective membership is computed on demand by walking each group's
 * `members` collection.
 *
 * Always carries the `member` role: per the PR 1 design, admin status
 * is a per-user attribute (you can't be admin "via a group"), so the
 * column exists for forward-compat / shape parity with SpaceMembership
 * but is constrained to `member` for now.
 */
#[ORM\Entity]
#[ORM\Table(name: 'space_group_membership')]
#[ORM\UniqueConstraint(name: 'uniq_space_group_membership', columns: ['space_id', 'user_group_id'])]
#[ORM\Index(columns: ['user_group_id'], name: 'idx_space_group_membership_group')]
class SpaceGroupMembership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['space:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class, inversedBy: 'groupMemberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Space $space = null;

    #[ORM\ManyToOne(targetEntity: UserGroup::class, inversedBy: 'spaceMemberships')]
    #[ORM\JoinColumn(name: 'user_group_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['space:read'])]
    private ?UserGroup $userGroup = null;

    #[ORM\Column(length: 16, options: ['default' => Space::ROLE_MEMBER])]
    #[Assert\Choice(
        choices: [Space::ROLE_MEMBER],
        message: 'Group memberships only support the member role.',
    )]
    #[Groups(['space:read'])]
    private string $role = Space::ROLE_MEMBER;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['space:read'])]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
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

    public function getUserGroup(): ?UserGroup
    {
        return $this->userGroup;
    }

    public function setUserGroup(?UserGroup $userGroup): static
    {
        $this->userGroup = $userGroup;
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
