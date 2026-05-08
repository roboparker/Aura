<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\SpaceRepository;
use App\State\SpaceCreateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Top-level container for content (#181). Every user has a personal
 * "Private" space, created on signup and not deletable. Additional
 * shared spaces can be created and shared with other users and
 * `UserGroup`s. Membership (`SpaceMembership` + `SpaceGroupMembership`)
 * is what gates access to the space's content; the `createdBy` field
 * is audit-only.
 *
 * PR 1 ships only the Space entity, its memberships, and a backfill
 * that links existing projects/discussions/custom-field-definitions to
 * their creator's personal space. The access-extension rewrite that
 * actually swaps Project/Discussion/CFD permissions over to space
 * membership lands in PR 2 (#185).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: SpaceCreateProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER')",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAdmin(user))",
            securityMessage: 'Only space admins can edit a space.',
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAdmin(user)) and not object.getIsPersonal()",
            securityMessage: 'Personal spaces cannot be deleted; shared spaces can only be deleted by an admin.',
        ),
    ],
    normalizationContext: ['groups' => ['space:read']],
    denormalizationContext: ['groups' => ['space:write']],
    order: ['isPersonal' => 'DESC', 'createdAt' => 'ASC'],
)]
#[ORM\Entity(repositoryClass: SpaceRepository::class)]
#[ORM\Table(name: 'space')]
#[ORM\Index(columns: ['created_by_id'], name: 'idx_space_created_by')]
// Mirror the partial unique index from Version20260507000000 so
// doctrine:schema:validate doesn't try to drop it. Postgres allows
// `WHERE` predicates on unique indexes — Doctrine encodes that via
// the `where` option here.
#[ORM\UniqueConstraint(
    name: 'uniq_space_personal_per_user',
    columns: ['created_by_id'],
    options: ['where' => '(is_personal = true)'],
)]
class Space
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public const ALLOWED_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_MEMBER,
    ];

    public const MAX_NAME_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 100_000;

    public const PERSONAL_SPACE_NAME = 'Private';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['space:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(
        max: self::MAX_NAME_LENGTH,
        maxMessage: 'Name cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['space:read', 'space:write'])]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(
        max: self::MAX_DESCRIPTION_LENGTH,
        maxMessage: 'Description cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['space:read', 'space:write'])]
    private ?string $description = null;

    /**
     * True only for the auto-created personal space attached to each
     * user at signup. Write-only at the system level — never settable
     * via the API (no `space:write` group on the property), so client
     * payloads can't fake a personal space, and the value is fixed for
     * the lifetime of the row.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['space:read'])]
    private bool $isPersonal = false;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['space:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Audit-only — the user who originally created the space. Kept
     * nullable so user deletion doesn't take shared spaces with it
     * (control comes from membership, not creation). For personal
     * spaces, a partial unique index in the migration enforces that a
     * user has at most one personal space.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['space:read'])]
    private ?User $createdBy = null;

    /**
     * Direct user memberships. Populated by SpaceCreateProcessor for
     * new shared spaces and by UserPasswordHasherProcessor for the
     * personal space at signup. PR 3 (#186) will add the invite-by-email
     * flow that lets admins extend this collection.
     *
     * @var Collection<int, SpaceMembership>
     */
    #[ORM\OneToMany(
        mappedBy: 'space',
        targetEntity: SpaceMembership::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[Groups(['space:read'])]
    private Collection $userMemberships;

    /**
     * Group memberships — adds every member of a `UserGroup` to the
     * space transitively. PR 1 only models the relationship; the
     * management UI for adding groups lands in PR 4 (#187).
     *
     * @var Collection<int, SpaceGroupMembership>
     */
    #[ORM\OneToMany(
        mappedBy: 'space',
        targetEntity: SpaceGroupMembership::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[Groups(['space:read'])]
    private Collection $groupMemberships;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->userMemberships = new ArrayCollection();
        $this->groupMemberships = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
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

    public function getIsPersonal(): bool
    {
        return $this->isPersonal;
    }

    /**
     * System-only setter — invoked by UserPasswordHasherProcessor when
     * provisioning the new user's personal space. Not exposed via any
     * serialization group so client payloads can't toggle this.
     */
    public function setIsPersonal(bool $isPersonal): static
    {
        $this->isPersonal = $isPersonal;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /**
     * @return Collection<int, SpaceMembership>
     */
    public function getUserMemberships(): Collection
    {
        return $this->userMemberships;
    }

    public function addUserMembership(SpaceMembership $membership): static
    {
        if (!$this->userMemberships->contains($membership)) {
            $this->userMemberships->add($membership);
            $membership->setSpace($this);
        }
        return $this;
    }

    public function removeUserMembership(SpaceMembership $membership): static
    {
        $this->userMemberships->removeElement($membership);
        return $this;
    }

    /**
     * @return Collection<int, SpaceGroupMembership>
     */
    public function getGroupMemberships(): Collection
    {
        return $this->groupMemberships;
    }

    public function addGroupMembership(SpaceGroupMembership $membership): static
    {
        if (!$this->groupMemberships->contains($membership)) {
            $this->groupMemberships->add($membership);
            $membership->setSpace($this);
        }
        return $this;
    }

    public function removeGroupMembership(SpaceGroupMembership $membership): static
    {
        $this->groupMemberships->removeElement($membership);
        return $this;
    }

    /**
     * True if the user holds a direct user membership in this space
     * with the admin role. Group-inherited admin is intentionally NOT
     * supported — admin role is a per-user property so we can audit
     * "who can delete this space" without a graph traversal. Used by
     * the entity-level security expressions for Patch/Delete.
     */
    public function isAdmin(?User $user): bool
    {
        if (null === $user) {
            return false;
        }
        foreach ($this->userMemberships as $membership) {
            if ($membership->getUser() === $user
                && $membership->getRole() === self::ROLE_ADMIN
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if the user is a member of this space, either directly or
     * transitively via one of their `UserGroup`s. Direct membership is
     * checked first because the common case is a personal space where
     * group-resolution would be wasted work.
     */
    public function hasMember(?User $user): bool
    {
        if (null === $user) {
            return false;
        }
        foreach ($this->userMemberships as $membership) {
            if ($membership->getUser() === $user) {
                return true;
            }
        }
        foreach ($this->groupMemberships as $groupMembership) {
            $group = $groupMembership->getUserGroup();
            if (null !== $group && $group->getMembers()->contains($user)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Deduplicated list of every user with access to this space —
     * direct members plus every member of every attached group. Used by
     * controllers / services that need the full universe of users in a
     * space (assignee picker, mention parsing, attachment uploader
     * gate). Keys are stringified user UUIDs so callers can quickly
     * test membership without rebuilding a lookup table.
     *
     * @return array<string, User>
     */
    public function getEffectiveUsers(): array
    {
        $bag = [];
        foreach ($this->userMemberships as $membership) {
            $u = $membership->getUser();
            if (null !== $u && null !== $u->getId()) {
                $bag[(string) $u->getId()] = $u;
            }
        }
        foreach ($this->groupMemberships as $groupMembership) {
            $group = $groupMembership->getUserGroup();
            if (null === $group) {
                continue;
            }
            foreach ($group->getMembers() as $member) {
                if (null !== $member->getId()) {
                    $bag[(string) $member->getId()] = $member;
                }
            }
        }
        return $bag;
    }
}
