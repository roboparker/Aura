<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
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
 * Named set of users owned by a single {@see Space} (#groups-space). Any
 * member of the group's space can edit metadata, manage membership, or delete
 * the group — there is no per-group owner. Every member of the group gains
 * access to the group's space transitively (the group is the access-granting
 * unit), so a group's reach is always scoped to that one space.
 *
 * Named "UserGroup" rather than "Group" because (a) `group` is a reserved
 * SQL keyword and (b) `Group` clashes with `Symfony\Component\Serializer\
 * Attribute\Groups`. The PWA still labels it "Group" in the UI.
 *
 * Membership is modelled by {@see UserGroupMembership} (one row per user,
 * carrying a `joinedAt`) rather than a plain join table so the detail UI
 * can show when each member joined. Member add/remove and leaving the group
 * run through dedicated controllers — there is no `group:write` on
 * `memberships`, so a stolen cookie can't reshape the roster via a plain PATCH.
 */
#[ApiResource(
    shortName: 'Group',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and object.getSpace() !== null and (is_granted('ROLE_ADMIN') or object.getSpace().hasMember(user))",
            processor: UserGroupOwnerProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().hasMember(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().hasMember(user))",
            securityMessage: "Only members of the group's space can edit the group.",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().hasMember(user))",
            securityMessage: "Only members of the group's space can delete the group.",
        ),
    ],
    normalizationContext: ['groups' => ['group:read']],
    denormalizationContext: ['groups' => ['group:write']],
    order: ['createdOn' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact'])]
#[ORM\Entity(repositoryClass: UserGroupRepository::class)]
#[ORM\Table(name: 'user_group')]
#[ORM\Index(columns: ['space_id'], name: 'idx_user_group_space')]
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
     * The space that owns the group (#groups-space). A group belongs to
     * exactly one space; every group member gains access to that space.
     * Set on create from the request payload (immutable after).
     */
    #[ORM\ManyToOne(targetEntity: Space::class, inversedBy: 'groups')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['group:write'])]
    private ?Space $space = null;

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
     * back to the group's space color (then the avatar palette). Constrained
     * to the same WCAG-AA palette as user avatars so white initials stay legible.
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
     * The group's membership roster. The creator is added here automatically
     * by UserGroupOwnerProcessor so a new group always has at least one member.
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

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        // Timestampable populates this on update; seed it at construct
        // time so newly-built rows have a valid value before first flush
        // (the column is NOT NULL).
        $this->updatedAt = new \DateTime();
        $this->memberships = new ArrayCollection();
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
     * Lean summary of the group's owning space, for the detail page's
     * "Belongs to" card and color fallback. Returned as a plain array so the
     * minimal fields the UI needs don't pull the full Space serialization
     * graph into the `group:read` context.
     *
     * @return array{id: string, name: string, color: string|null, isPersonal: bool}|null
     */
    #[Groups(['group:read'])]
    public function getSpaceSummary(): ?array
    {
        if (null === $this->space || null === $this->space->getId()) {
            return null;
        }
        return [
            'id' => (string) $this->space->getId(),
            'name' => $this->space->getName(),
            'color' => $this->space->getColor(),
            'isPersonal' => $this->space->getIsPersonal(),
        ];
    }
}
