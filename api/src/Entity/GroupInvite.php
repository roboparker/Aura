<?php

namespace App\Entity;

use App\Repository\GroupInviteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Join row connecting a UserInvite to a UserGroup the invitee will join
 * once they sign up. Carrying its own `invitedBy` lets the signup page
 * show who invited the new user to each specific group when there are
 * multiple. Unique on (userInvite, group) so re-inviting the same email
 * to the same group is a no-op rather than a duplicate row.
 *
 * Not exposed as an API Platform resource — it's managed through
 * UserGroupMemberController (creation) and UserInviteController (read,
 * revoke).
 */
#[ORM\Entity(repositoryClass: GroupInviteRepository::class)]
#[ORM\Table(name: 'group_invite')]
#[ORM\UniqueConstraint(
    name: 'uniq_group_invite_invite_group',
    columns: ['user_invite_id', 'user_group_id'],
)]
#[ORM\Index(columns: ['user_invite_id'], name: 'idx_group_invite_invite')]
#[ORM\Index(columns: ['user_group_id'], name: 'idx_group_invite_group')]
class GroupInvite
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: UserInvite::class, inversedBy: 'groupInvites')]
    #[ORM\JoinColumn(name: 'user_invite_id', nullable: false, onDelete: 'CASCADE')]
    private UserInvite $userInvite;

    #[ORM\ManyToOne(targetEntity: UserGroup::class)]
    #[ORM\JoinColumn(name: 'user_group_id', nullable: false, onDelete: 'CASCADE')]
    private UserGroup $group;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $invitedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(UserInvite $userInvite, UserGroup $group, User $invitedBy)
    {
        $this->userInvite = $userInvite;
        $this->group = $group;
        $this->invitedBy = $invitedBy;
        $this->createdAt = new \DateTimeImmutable();
        $userInvite->getGroupInvites()->add($this);
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUserInvite(): UserInvite
    {
        return $this->userInvite;
    }

    public function getGroup(): UserGroup
    {
        return $this->group;
    }

    public function getInvitedBy(): User
    {
        return $this->invitedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
