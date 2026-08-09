<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Deletion\SoftDeletable;
use App\Deletion\SoftDeletableTrait;
use App\Deletion\SoftDeletionService;
use App\Repository\OrganizationRepository;
use App\State\OrganizationCreateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

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
#[ApiResource(
    operations: [
        // Scoped to the caller's organizations by OrganizationAccessExtension.
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')", processor: OrganizationCreateProcessor::class),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.hasMember(user))",
            securityMessage: 'Organization not found.',
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAdmin(user))",
            securityMessage: 'Only organization admins can edit it.',
        ),
    ],
    normalizationContext: ['groups' => ['organization:read']],
    denormalizationContext: ['groups' => ['organization:write']],
    order: ['createdAt' => 'DESC'],
)]
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[ORM\UniqueConstraint(name: 'uniq_organization_slug', columns: ['slug'])]
#[ORM\Index(columns: ['purge_after'], name: 'idx_organization_purge_after')]
// One personal organization per user, enforced at the DB rather than in code:
// a duplicate would give someone two personal accounts and two places a plan
// could live. Mirrors `uniq_space_personal_per_user`.
#[ORM\UniqueConstraint(
    name: 'uniq_organization_personal_per_user',
    columns: ['created_by_id'],
    options: ['where' => '(is_personal = true)'],
)]
class Organization implements SoftDeletable
{
    /**
     * Deletion grace period: `deletedAt` / `purgeAfter` and their transitions
     * come from {@see SoftDeletableTrait}, shared with Space and User so all
     * three behave identically. Non-null `deletedAt` puts the org in its
     * window — its spaces stop being reachable (the access extensions exclude
     * them) but nothing is destroyed until the nightly purge.
     */
    use SoftDeletableTrait;

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
    #[Assert\NotBlank(message: 'An organization name is required.')]
    #[Assert\Length(max: 255)]
    #[Groups(['organization:read', 'organization:write'])]
    private string $name = '';

    /** Stable 'o-'-prefixed handle, generated once from the name; read-only. */
    #[ORM\Column(length: 255)]
    #[Groups(['organization:read'])]
    private string $slug = '';

    /**
     * A user's own account, provisioned at signup and owning their personal
     * space (#billing Phase 2). Exactly the role a GitHub personal account
     * plays: it holds a plan and owns spaces, so nothing in the model has to
     * special-case "a space with no account behind it".
     *
     * Non-deletable and non-leavable — it goes only when its owner's account
     * does. Server-written: never in `organization:write`, so it can't be set
     * or cleared through the API.
     */
    #[ORM\Column]
    #[Groups(['organization:read'])]
    private bool $isPersonal = false;

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

    public function getIsPersonal(): bool
    {
        return $this->isPersonal;
    }

    public function setIsPersonal(bool $isPersonal): static
    {
        $this->isPersonal = $isPersonal;

        return $this;
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
     * Grace-period state on the wire. Exposed through grouped getters rather
     * than on the trait's properties: the trait is shared with Space, and a
     * `space:read` group there would drag the whole organization inline into
     * every Space payload instead of an IRI.
     */
    #[Groups(['organization:read'])]
    #[SerializedName('deletedAt')]
    public function getDeletedAtIso(): ?string
    {
        return $this->deletedAt?->format(\DateTimeInterface::ATOM);
    }

    #[Groups(['organization:read'])]
    #[SerializedName('purgeAfter')]
    public function getPurgeAfterIso(): ?string
    {
        return $this->purgeAfter?->format(\DateTimeInterface::ATOM);
    }

    public function deletionTargetType(): string
    {
        return SoftDeletionService::TYPE_ORGANIZATION;
    }

    public function deletionLabel(): string
    {
        return $this->name;
    }

    protected function touchOnDeletionChange(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Number of paid seats (members whose role isn't guest). */
    #[Groups(['organization:read'])]
    public function getSeatCount(): int
    {
        return $this->seatCount();
    }

    /**
     * Flattened member roster for the detail UI (avoids embedding + a User
     * serialization group).
     *
     * @return list<array{id: string, role: string, joinedAt: string, user: array<string, mixed>}>
     */
    #[Groups(['organization:read'])]
    public function getMemberList(): array
    {
        $out = [];
        foreach ($this->memberships as $membership) {
            $user = $membership->getUser();
            if (null === $user) {
                continue;
            }
            $out[] = [
                'id' => (string) $membership->getId(),
                'role' => $membership->getRole(),
                'joinedAt' => $membership->getJoinedAt()->format(\DateTimeInterface::ATOM),
                'user' => [
                    'id' => (string) $user->getId(),
                    'email' => $user->getEmail(),
                    'givenName' => $user->getGivenName(),
                    'familyName' => $user->getFamilyName(),
                    'nickname' => $user->getNickname(),
                    'personalizedColor' => $user->getPersonalizedColor(),
                    'avatarUrls' => $user->getAvatarUrls(),
                ],
            ];
        }

        return $out;
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
