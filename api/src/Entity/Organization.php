<?php

namespace App\Entity;

use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * An organization account (#billing Phase 1) — the GitHub-style team account
 * that owns shared {@see Space}s and carries an org plan (Free/Business/
 * Enterprise). Distinct from a personal account (a {@see User}, which owns its
 * own personal spaces on a Free/Pro plan).
 *
 * Members hold an {@see OrganizationMembership} with an org-level role. Org
 * roles govern the *account* (billing, members, settings); the per-space
 * {@see SpaceRole} governs *content* — two independent layers. All non-`guest`
 * roles count as a billable seat; guests are free.
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[ORM\UniqueConstraint(name: 'uniq_organization_slug', columns: ['slug'])]
class Organization
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_BILLING = 'billing';
    public const ROLE_MEMBER = 'member';
    public const ROLE_GUEST = 'guest';

    /** @var list<string> */
    public const ALLOWED_ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_BILLING,
        self::ROLE_MEMBER,
        self::ROLE_GUEST,
    ];

    /** Roles that consume a paid seat (everything but guest). */
    public const BILLABLE_ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_BILLING,
        self::ROLE_MEMBER,
    ];

    /** Roles with account-management authority (members/settings/roles). */
    public const ADMIN_ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['organization:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['organization:read', 'organization:write'])]
    private string $name = '';

    /** Stable 'o-'-prefixed handle, generated once from the name; read-only. */
    #[ORM\Column(length: 255)]
    #[Groups(['organization:read'])]
    private string $slug = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['organization:read'])]
    private ?User $createdBy = null;

    /**
     * @var Collection<int, OrganizationMembership>
     */
    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: OrganizationMembership::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $memberships;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['organization:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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
        $this->updatedAt = new \DateTimeImmutable();
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
     * @return Collection<int, OrganizationMembership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * True if the user is a member. UUID-based so it survives
     * EntityManager::clear() (unlike an identity-map object compare).
     */
    public function hasMember(User $user): bool
    {
        return null !== $this->membershipFor($user);
    }

    /** The user's org role, or null if they're not a member. */
    public function roleFor(User $user): ?string
    {
        return $this->membershipFor($user)?->getRole();
    }

    /** True if the user can manage the account (owner or admin). */
    public function isAdmin(User $user): bool
    {
        $role = $this->roleFor($user);

        return null !== $role && in_array($role, self::ADMIN_ROLES, true);
    }

    /** True if the user owns the account. */
    public function isOwner(User $user): bool
    {
        return self::ROLE_OWNER === $this->roleFor($user);
    }

    /** Distinct members occupying a paid seat (everyone but guests). */
    public function seatCount(): int
    {
        $seats = 0;
        foreach ($this->memberships as $membership) {
            if (in_array($membership->getRole(), self::BILLABLE_ROLES, true)) {
                ++$seats;
            }
        }

        return $seats;
    }

    public function addMember(User $user, string $role = self::ROLE_MEMBER): OrganizationMembership
    {
        $existing = $this->membershipFor($user);
        if (null !== $existing) {
            return $existing->setRole($role);
        }
        $membership = (new OrganizationMembership())
            ->setOrganization($this)
            ->setUser($user)
            ->setRole($role);
        $this->memberships->add($membership);

        return $membership;
    }

    public function removeMember(User $user): static
    {
        $membership = $this->membershipFor($user);
        if (null !== $membership) {
            $this->memberships->removeElement($membership);
        }

        return $this;
    }

    private function membershipFor(User $user): ?OrganizationMembership
    {
        $userId = $user->getId();
        if (null === $userId) {
            return null;
        }
        foreach ($this->memberships as $membership) {
            if (true === $membership->getUser()?->getId()?->equals($userId)) {
                return $membership;
            }
        }

        return null;
    }
}
