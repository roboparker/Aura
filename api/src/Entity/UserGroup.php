<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\UserGroupRepository;
use App\Service\AvatarColorService;
use App\State\UserGroupOwnerProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Reusable named set of users. The owner has full control: edit metadata,
 * manage membership, transfer ownership, or delete the group. Other members
 * are read-only — they appear in the group's listings but cannot mutate it.
 *
 * Named "UserGroup" rather than "Group" because (a) `group` is a reserved
 * SQL keyword and (b) `Group` clashes with `Symfony\Component\Serializer\
 * Attribute\Groups`. The PWA still labels it "Group" in the UI.
 *
 * Membership is modelled by {@see UserGroupMembership} (one row per user,
 * carrying a `joinedAt`) rather than a plain join table so the detail UI
 * can show when each member joined. Transfer ownership, member removal,
 * and leaving the group all run through dedicated controllers — there is
 * no `group:write` on `owner` or `memberships`, so a stolen cookie can't
 * silently reassign the group via a plain PATCH.
 */
#[ApiResource(
    shortName: 'Group',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: UserGroupOwnerProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER')",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user)",
            securityMessage: 'Only the group owner can edit the group.',
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getOwner() == user)",
            securityMessage: 'Only the group owner can delete the group.',
        ),
    ],
    normalizationContext: ['groups' => ['group:read']],
    denormalizationContext: ['groups' => ['group:write']],
    order: ['createdOn' => 'DESC'],
)]
#[ORM\Entity(repositoryClass: UserGroupRepository::class)]
#[ORM\Table(name: 'user_group')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_user_group_owner')]
#[ORM\UniqueConstraint(name: 'uniq_user_group_slug', columns: ['slug'])]
class UserGroup
{
    public const MAX_TITLE_LENGTH = 255;
    public const MAX_SLUG_LENGTH = 50;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['group:read'])]
    private ?Uuid $id = null;

    /**
     * The user who currently controls the group. Set by
     * UserGroupOwnerProcessor on create; reassigned via the dedicated,
     * step-up-protected transfer endpoint (not via PATCH).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['group:read'])]
    private ?User $owner = null;

    #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(max: self::MAX_TITLE_LENGTH, maxMessage: 'Title cannot be longer than {{ limit }} characters.')]
    #[Groups(['group:read', 'group:write'])]
    private string $title = '';

    /**
     * Stable, URL-friendly handle ("creative", "eng", …) rendered in the
     * UI with a `g-` prefix ("g-creative"). Generated once from the title
     * on create by {@see UserGroupOwnerProcessor} and intentionally NOT
     * regenerated on rename — like a username, it's a stable identifier
     * people may reference. Read-only on the wire (no `group:write`).
     */
    #[ORM\Column(length: self::MAX_SLUG_LENGTH)]
    #[Groups(['group:read'])]
    private string $slug = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 100000, maxMessage: 'Description cannot be longer than {{ limit }} characters.')]
    #[Groups(['group:read', 'group:write'])]
    private ?string $description = null;

    /**
     * Override color for the group avatar tile. When null the UI falls
     * back to the owner's `personalizedColor`. Constrained to the same
     * WCAG-AA palette as user avatars so white initials stay legible.
     */
    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Choice(
        choices: AvatarColorService::PALETTE,
        message: 'Color must be one of the supported avatar palette values.',
    )]
    #[Groups(['group:read', 'group:write'])]
    private ?string $color = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['group:read'])]
    private \DateTimeImmutable $createdOn;

    /**
     * Maintained by Gedmo Timestampable on every UPDATE — powers the
     * "edited 2h ago" / "updated yesterday" line on the groups list and
     * the "updated" row in the detail's At-a-glance card. Stored as
     * `datetime` (not immutable) so Gedmo can mutate it in place.
     */
    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    #[Groups(['group:read'])]
    private \DateTimeInterface $updatedAt;

    /**
     * The group's membership roster. The owner is added here automatically
     * by UserGroupOwnerProcessor so access checks can rely solely on
     * memberships without a special case for the owner.
     *
     * @var Collection<int, UserGroupMembership>
     */
    #[ORM\OneToMany(
        mappedBy: 'group',
        targetEntity: UserGroupMembership::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['joinedAt' => 'ASC'])]
    #[Groups(['group:read'])]
    private Collection $memberships;

    /**
     * Inverse side of the spaces this group is attached to (each row adds
     * every group member to a space transitively). Read-only; surfaced as
     * a flattened summary by {@see getAttachedSpaces()}.
     *
     * @var Collection<int, SpaceGroupMembership>
     */
    #[ORM\OneToMany(
        mappedBy: 'userGroup',
        targetEntity: SpaceGroupMembership::class,
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $spaceMemberships;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        // Timestampable populates this on update; seed it at construct
        // time so newly-built rows have a valid value before first flush
        // (the column is NOT NULL).
        $this->updatedAt = new \DateTime();
        $this->memberships = new ArrayCollection();
        $this->spaceMemberships = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getCreatedOn(): \DateTimeImmutable
    {
        return $this->createdOn;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, UserGroupMembership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    /**
     * Convenience view of the roster as a collection of `User`s, derived
     * from the membership rows. Kept so the many existing call sites that
     * iterate / count members (Space::getEffectiveUsers, the access
     * extension, invite flows) don't all need to learn about the join
     * entity. Read-only — mutate via {@see addMember()} / {@see removeMember()}.
     *
     * @return Collection<int, User>
     */
    public function getMembers(): Collection
    {
        $users = new ArrayCollection();
        foreach ($this->memberships as $membership) {
            $user = $membership->getUser();
            if (null !== $user) {
                $users->add($user);
            }
        }
        return $users;
    }

    /**
     * UUID-based membership test — survives EntityManager::clear() where
     * identity comparison (`getMembers()->contains()`) would not.
     */
    public function hasMember(?User $user): bool
    {
        if (null === $user || null === $user->getId()) {
            return false;
        }
        foreach ($this->memberships as $membership) {
            $rowUser = $membership->getUser();
            if (null !== $rowUser && null !== $rowUser->getId() && $user->getId()->equals($rowUser->getId())) {
                return true;
            }
        }
        return false;
    }

    public function addMember(User $member): static
    {
        if ($this->hasMember($member)) {
            return $this;
        }
        $membership = new UserGroupMembership();
        $membership->setUser($member);
        $membership->setGroup($this);
        $this->memberships->add($membership);
        return $this;
    }

    public function removeMember(User $member): static
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser() === $member) {
                $this->memberships->removeElement($membership);
                break;
            }
            $rowUser = $membership->getUser();
            if (
                null !== $rowUser
                && null !== $rowUser->getId()
                && null !== $member->getId()
                && $member->getId()->equals($rowUser->getId())
            ) {
                $this->memberships->removeElement($membership);
                break;
            }
        }
        return $this;
    }

    /**
     * Flattened summary of the spaces this group is attached to, for the
     * detail page's "Attached to" card. Returned as plain arrays so the
     * minimal space fields the UI needs don't pull the full Space
     * serialization graph into the `group:read` context.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     color: string|null,
     *     isPersonal: bool,
     *     ownerColor: string|null,
     *     role: string,
     *     since: string
     * }>
     */
    #[Groups(['group:read'])]
    public function getAttachedSpaces(): array
    {
        $out = [];
        foreach ($this->spaceMemberships as $membership) {
            $space = $membership->getSpace();
            if (null === $space || null === $space->getId()) {
                continue;
            }
            $out[] = [
                'id' => (string) $space->getId(),
                'name' => $space->getName(),
                'color' => $space->getColor(),
                'isPersonal' => $space->getIsPersonal(),
                'ownerColor' => $space->getCreatedBy()?->getPersonalizedColor(),
                'role' => $membership->getRole(),
                'since' => $membership->getJoinedAt()->format(\DateTimeInterface::ATOM),
            ];
        }
        return $out;
    }
}
