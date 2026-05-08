<?php

namespace App\Entity;

use App\Repository\SpaceInviteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Join row connecting a `UserInvite` to a `Space` the invitee will join
 * once they sign up (#186). Carrying its own `invitedBy` lets the
 * signup page show who invited the new user to each specific space
 * when there are multiple. Unique on (userInvite, space) so re-inviting
 * the same email to the same space is a no-op rather than a duplicate
 * row. Mirrors {@see GroupInvite}.
 *
 * Always carries `member` for now — admin promotion lives behind a
 * separate space-management flow rather than the signup link, but the
 * column is wired so PR 4 can introduce admin invites without a
 * migration.
 *
 * Not exposed as an API Platform resource — managed through
 * `SpaceMemberController` (creation) and `SpaceInviteController`
 * (read, revoke).
 */
#[ORM\Entity(repositoryClass: SpaceInviteRepository::class)]
#[ORM\Table(name: 'space_invite')]
#[ORM\UniqueConstraint(
    name: 'uniq_space_invite_invite_space',
    columns: ['user_invite_id', 'space_id'],
)]
#[ORM\Index(columns: ['user_invite_id'], name: 'idx_space_invite_invite')]
#[ORM\Index(columns: ['space_id'], name: 'idx_space_invite_space')]
class SpaceInvite
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: UserInvite::class, inversedBy: 'spaceInvites')]
    #[ORM\JoinColumn(name: 'user_invite_id', nullable: false, onDelete: 'CASCADE')]
    private UserInvite $userInvite;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(name: 'space_id', nullable: false, onDelete: 'CASCADE')]
    private Space $space;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $invitedBy;

    #[ORM\Column(length: 16, options: ['default' => Space::ROLE_MEMBER])]
    #[Assert\Choice(choices: Space::ALLOWED_ROLES)]
    private string $role = Space::ROLE_MEMBER;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        UserInvite $userInvite,
        Space $space,
        User $invitedBy,
        string $role = Space::ROLE_MEMBER,
    ) {
        $this->userInvite = $userInvite;
        $this->space = $space;
        $this->invitedBy = $invitedBy;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
        $userInvite->getSpaceInvites()->add($this);
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUserInvite(): UserInvite
    {
        return $this->userInvite;
    }

    public function getSpace(): Space
    {
        return $this->space;
    }

    public function getInvitedBy(): User
    {
        return $this->invitedBy;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
