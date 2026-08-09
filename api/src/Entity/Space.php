<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Deletion\SoftDeletable;
use App\Deletion\SoftDeletableTrait;
use App\Deletion\SoftDeletionService;
use App\Repository\SpaceRepository;
use App\Service\AvatarColorService;
use App\State\SpaceCreateProcessor;
use App\State\SpaceDeleteProcessor;
use App\State\SpaceUpdateProcessor;
use App\Validator\ValidSpaceAttachments;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Top-level container for content (#181). Every user has a personal
 * "Private" space, created on signup and not deletable. Additional
 * shared spaces can be created and shared with other users and
 * `UserGroup`s (a group is owned by one space; its members get access to
 * that space). Membership — direct `SpaceMembership` plus the members of the
 * space's `UserGroup`s — is what gates access to the space's content; the
 * `createdBy` field is audit-only.
 *
 * PR 1 ships only the Space entity, its memberships, and a backfill
 * that links existing boards/custom-field-definitions to
 * their creator's personal space. The access-extension rewrite that
 * actually swaps Board/CFD permissions over to space
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
            processor: SpaceUpdateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.isAdmin(user)) and not object.getIsPersonal()",
            securityMessage: 'Personal spaces cannot be deleted; shared spaces can only be deleted by an admin.',
            processor: SpaceDeleteProcessor::class,
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
#[ORM\Index(columns: ['purge_after'], name: 'idx_space_purge_after')]
#[ValidSpaceAttachments]
class Space implements SoftDeletable
{
    /**
     * Deletion grace period, shared with Organization and User. Deleting a
     * space cascades to every board, task and page in it, so it gets the same
     * window + emailed restore link as the other two rather than vanishing on
     * confirm.
     */
    use SoftDeletableTrait;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public const ALLOWED_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_MEMBER,
    ];

    /**
     * Visibility is orthogonal to membership role: it controls whether
     * additional users can be added at all. Private spaces are
     * single-occupant (creator only); shared spaces accept invites and
     * group memberships. Personal spaces are always private — enforced
     * by {@see validatePersonalIsPrivate()}.
     */
    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_SHARED = 'shared';

    public const ALLOWED_VISIBILITIES = [
        self::VISIBILITY_PRIVATE,
        self::VISIBILITY_SHARED,
    ];

    public const MAX_NAME_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 100_000;

    public const PERSONAL_SPACE_NAME = 'Private';

    public const MAX_INITIAL_INVITES = 50;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['space:read', 'page:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(
        max: self::MAX_NAME_LENGTH,
        maxMessage: 'Name cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['space:read', 'space:write', 'page:read'])]
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

    /**
     * Controls whether the space accepts additional members. `private`
     * spaces are creator-only — invite endpoints and add-member reject
     * with 409, and {@see SpaceUpdateProcessor} kicks every other
     * membership on a shared → private transition. `shared` is the
     * default for new spaces; personal spaces are always `private`,
     * enforced by {@see validatePersonalIsPrivate()}.
     */
    #[ORM\Column(length: 16, options: ['default' => self::VISIBILITY_SHARED])]
    #[Assert\Choice(
        choices: self::ALLOWED_VISIBILITIES,
        message: 'Visibility must be "private" or "shared".',
    )]
    #[Groups(['space:read', 'space:write'])]
    private string $visibility = self::VISIBILITY_SHARED;

    /**
     * Override color for the space avatar tile. When null the UI falls
     * back to the creator's `personalizedColor` — same swatch on the
     * person and their personal space by default, with the option to
     * pick a different one for shared spaces (or override the personal
     * one). Constrained to the same WCAG-AA palette as user avatars so
     * white initials always render legibly.
     */
    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Choice(
        choices: AvatarColorService::PALETTE,
        message: 'Color must be one of the supported avatar palette values.',
    )]
    #[Groups(['space:read', 'space:write'])]
    private ?string $color = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['space:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Maintained by Gedmo Timestampable on every UPDATE. Powers the
     * "edited 2h ago" / "updated yesterday" line on the spaces grid
     * — it tracks edits to the space row itself (name, description,
     * visibility), not activity on its contained boards / pages.
     *
     * Stored as `datetime` rather than `datetime_immutable` so Gedmo
     * can mutate the field in place on update.
     */
    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    #[Groups(['space:read'])]
    private \DateTimeInterface $updatedAt;

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
     * The organization account that owns this space (#billing Phase 1), or null
     * for a **personal** space (owned by {@see $createdBy}). This is the
     * GitHub-style split: a space belongs to a person or an org, never both. The
     * plan a space runs on resolves through this owner (see PlanGate).
     */
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['space:read', 'space:write'])]
    private ?Organization $organization = null;

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
     * Groups owned by this space (#groups-space). Every member of each group
     * gains access to the space transitively, so groups are this space's
     * access-granting "teams". A group belongs to exactly one space.
     *
     * @var Collection<int, UserGroup>
     */
    #[ORM\OneToMany(
        mappedBy: 'space',
        targetEntity: UserGroup::class,
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $groups;

    /**
     * MediaObjects attached at the space level (cover docs, shared
     * specs, anything not pinned to a specific task). Replaces the
     * old `board_attachment` join — attachments now live at the
     * space so they're available to every board + member in it.
     * Membership is edited via PATCH on the Space with an
     * `attachments` array of MediaObject IRIs; the PWA uploads via
     * `POST /media-objects` (kind=attachment) first.
     * {@see ValidSpaceAttachments} enforces uploader-is-member +
     * kind=attachment.
     *
     * @var Collection<int, MediaObject>
     */
    #[ORM\ManyToMany(targetEntity: MediaObject::class)]
    #[ORM\JoinTable(name: 'space_attachment')]
    #[ORM\JoinColumn(name: 'space_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'media_object_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['space:read', 'space:write'])]
    private Collection $attachments;

    /**
     * Invoice branding (#669): logo rendered on the invoice PDF + the public
     * invoice/estimate pages. Uploaded as an avatar-kind MediaObject so the
     * public pages can use the plain `/media/...` URL; the PDF embeds the
     * bytes as a data URI (dompdf runs with remote fetches disabled).
     */
    #[\ApiPlatform\Metadata\ApiProperty(readableLink: true)]
    #[ORM\ManyToOne(targetEntity: MediaObject::class)]
    #[ORM\JoinColumn(name: 'invoice_logo_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['space:read', 'space:write'])]
    private ?MediaObject $invoiceLogo = null;

    /** Default terms/footer text printed on every invoice (#669). */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    #[Groups(['space:read', 'space:write'])]
    private ?string $invoiceTerms = null;

    /** Invoice number prefix, e.g. "ACME-" (default "INV-") (#669). */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9._-]*$/', message: 'Use letters, digits, dots, dashes.')]
    #[Groups(['space:read', 'space:write'])]
    private ?string $invoiceNumberPrefix = null;

    /**
     * Next sequential invoice number to assign (#669). Null = derive from
     * the count of already-numbered invoices (the pre-branding behaviour);
     * once set (by an admin, or by the numberer after each assignment) it
     * is authoritative and increments per issued invoice.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive]
    #[Groups(['space:read', 'space:write'])]
    private ?int $invoiceNumberNext = null;

    /**
     * The space's Stripe Connect (Express) account id — where client invoice
     * payments settle (#connect). Written server-side only by the onboarding
     * controller + the `account.updated` webhook; never on the wire (a raw
     * `acct_…` leaking to every member is pointless), so readiness is surfaced
     * through `GET /spaces/{id}/connect/status` instead of a read group.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $stripeConnectAccountId = null;

    /** Stripe says the connected account can accept charges (onboarding done). */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $stripeConnectChargesEnabled = false;

    /** The account has submitted its onboarding details (may still be pending review). */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $stripeConnectDetailsSubmitted = false;

    /**
     * Transient list of email addresses to invite at creation time —
     * consumed by {@see SpaceCreateProcessor}, never persisted. Validated
     * inline so a single bad address fails the whole create with 422
     * (atomic batch invite — partial success would leave the UX in a
     * state nobody asked for). All-personal duplicates and the creator's
     * own address are dropped in the processor without erroring.
     *
     * @var list<string>
     */
    #[Assert\All([
        new Assert\Email(message: '"{{ value }}" is not a valid email address.'),
        new Assert\Length(max: 180),
    ])]
    #[Assert\Count(
        max: self::MAX_INITIAL_INVITES,
        maxMessage: 'You can invite at most {{ limit }} people at once.',
    )]
    #[Groups(['space:write'])]
    private array $invites = [];

    /**
     * Inverse side for boards rooted in this space. EXTRA_LAZY means
     * `->count()` runs a single SELECT COUNT query without hydrating
     * rows, so {@see getBoardsCount()} stays cheap even on the
     * spaces-list endpoint. Off the wire — only the count getter is
     * serialized.
     *
     * @var Collection<int, Board>
     */
    #[ORM\OneToMany(
        mappedBy: 'space',
        targetEntity: Board::class,
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $boards;

    /**
     * Inverse side for pages rooted in this space. Same EXTRA_LAZY
     * shape as {@see $boards} — only the count is exposed.
     *
     * @var Collection<int, Page>
     */
    #[ORM\OneToMany(
        mappedBy: 'space',
        targetEntity: Page::class,
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $pages;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        // Timestampable populates this on update; seed it at construct
        // time so newly-built rows have a valid value before the first
        // flush (the column is NOT NULL).
        $this->updatedAt = new \DateTime();
        $this->userMemberships = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->boards = new ArrayCollection();
        $this->pages = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /** Grace-period state on the wire — see Organization for why these are getters. */
    #[Groups(['space:read'])]
    #[SerializedName('deletedAt')]
    public function getDeletedAtIso(): ?string
    {
        return $this->deletedAt?->format(\DateTimeInterface::ATOM);
    }

    #[Groups(['space:read'])]
    #[SerializedName('purgeAfter')]
    public function getPurgeAfterIso(): ?string
    {
        return $this->purgeAfter?->format(\DateTimeInterface::ATOM);
    }

    public function deletionTargetType(): string
    {
        return SoftDeletionService::TYPE_SPACE;
    }

    public function deletionLabel(): string
    {
        return $this->name;
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

    public function getInvoiceLogo(): ?MediaObject
    {
        return $this->invoiceLogo;
    }

    public function setInvoiceLogo(?MediaObject $invoiceLogo): static
    {
        $this->invoiceLogo = $invoiceLogo;

        return $this;
    }

    public function getInvoiceTerms(): ?string
    {
        return $this->invoiceTerms;
    }

    public function setInvoiceTerms(?string $invoiceTerms): static
    {
        $this->invoiceTerms = $invoiceTerms;

        return $this;
    }

    public function getStripeConnectAccountId(): ?string
    {
        return $this->stripeConnectAccountId;
    }

    public function setStripeConnectAccountId(?string $stripeConnectAccountId): static
    {
        $this->stripeConnectAccountId = $stripeConnectAccountId;

        return $this;
    }

    public function isStripeConnectChargesEnabled(): bool
    {
        return $this->stripeConnectChargesEnabled;
    }

    public function setStripeConnectChargesEnabled(bool $enabled): static
    {
        $this->stripeConnectChargesEnabled = $enabled;

        return $this;
    }

    public function isStripeConnectDetailsSubmitted(): bool
    {
        return $this->stripeConnectDetailsSubmitted;
    }

    public function setStripeConnectDetailsSubmitted(bool $submitted): static
    {
        $this->stripeConnectDetailsSubmitted = $submitted;

        return $this;
    }

    /**
     * Whether client invoice payments can be collected online for this space:
     * a connected account exists and Stripe has enabled charges on it.
     */
    public function canAcceptClientPayments(): bool
    {
        return null !== $this->stripeConnectAccountId && $this->stripeConnectChargesEnabled;
    }

    public function getInvoiceNumberPrefix(): ?string
    {
        return $this->invoiceNumberPrefix;
    }

    public function setInvoiceNumberPrefix(?string $invoiceNumberPrefix): static
    {
        $this->invoiceNumberPrefix = $invoiceNumberPrefix;

        return $this;
    }

    public function getInvoiceNumberNext(): ?int
    {
        return $this->invoiceNumberNext;
    }

    public function setInvoiceNumberNext(?int $invoiceNumberNext): static
    {
        $this->invoiceNumberNext = $invoiceNumberNext;

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

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function isPrivate(): bool
    {
        return self::VISIBILITY_PRIVATE === $this->visibility;
    }

    /**
     * Class-level invariant enforced after standard property validation:
     * personal spaces must always be private. Prevents a payload (or a
     * mistaken service-layer call) from leaving a user's auto-provisioned
     * personal bucket open to invites.
     */
    #[Assert\Callback]
    public function validatePersonalIsPrivate(ExecutionContextInterface $context): void
    {
        if ($this->isPersonal && self::VISIBILITY_PRIVATE !== $this->visibility) {
            $context->buildViolation('Personal spaces must be private.')
                ->atPath('visibility')
                ->addViolation();
        }
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

    /**
     * @return list<string>
     */
    public function getInvites(): array
    {
        return $this->invites;
    }

    /**
     * @param list<string> $invites
     */
    public function setInvites(array $invites): static
    {
        $this->invites = $invites;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * Board count rooted in this space. EXTRA_LAZY makes this a
     * single COUNT query — no row hydration.
     */
    #[Groups(['space:read'])]
    public function getBoardsCount(): int
    {
        return $this->boards->count();
    }

    /**
     * Page count rooted in this space. Includes every level of the page
     * tree (root pages + descendants) since access is by space, not by
     * parent — the grid copy reads "N pages" so all pages matter.
     */
    #[Groups(['space:read'])]
    public function getPagesCount(): int
    {
        return $this->pages->count();
    }

    /**
     * Number of groups owned by this space. EXTRA_LAZY → single COUNT query.
     */
    #[Groups(['space:read'])]
    public function getGroupsCount(): int
    {
        return $this->groups->count();
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

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;
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
     * @return Collection<int, UserGroup>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    /**
     * @return Collection<int, MediaObject>
     */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(MediaObject $media): static
    {
        if (!$this->attachments->contains($media)) {
            $this->attachments->add($media);
        }
        return $this;
    }

    public function removeAttachment(MediaObject $media): static
    {
        $this->attachments->removeElement($media);
        return $this;
    }

    /**
     * True if the user holds a direct user membership in this space
     * with the admin role. Group-inherited admin is intentionally NOT
     * supported — admin role is a per-user property so we can audit
     * "who can delete this space" without a graph traversal. Used by
     * the entity-level security expressions for Patch/Delete.
     *
     * Compares by UUID rather than identity so the check works after
     * an EntityManager::clear() — the membership's User row may be a
     * different PHP instance than the one the caller is holding.
     */
    public function isAdmin(?User $user): bool
    {
        return $this->hasUserAt($user, self::ROLE_ADMIN);
    }

    /**
     * True if the user is a member of this space, either directly or
     * transitively via one of their `UserGroup`s. Direct membership is
     * checked first because the common case is a personal space where
     * group-resolution would be wasted work. Like {@see isAdmin()},
     * comparison is UUID-based so detached User objects still match.
     */
    public function hasMember(?User $user): bool
    {
        if (null === $user || null === $user->getId()) {
            return false;
        }
        if ($this->hasUserAt($user, null)) {
            return true;
        }
        foreach ($this->groups as $group) {
            foreach ($group->getMembers() as $member) {
                if ($user->getId()->equals($member->getId())) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The member's space-level internal cost rate (#653), or null when
     * unset / not a direct member. UUID-based comparison like the other
     * membership helpers.
     */
    public function getCostRateFor(?User $user): ?int
    {
        if (null === $user || null === $user->getId()) {
            return null;
        }
        foreach ($this->userMemberships as $membership) {
            if (true === $membership->getUser()?->getId()?->equals($user->getId())) {
                return $membership->getCostRateAmount();
            }
        }

        return null;
    }

    /**
     * Shared scan for direct user memberships, optionally filtered to
     * a specific role. Pulled out so {@see isAdmin()} and
     * {@see hasMember()} stay one-liners.
     */
    private function hasUserAt(?User $user, ?string $role): bool
    {
        if (null === $user || null === $user->getId()) {
            return false;
        }
        foreach ($this->userMemberships as $membership) {
            $rowUser = $membership->getUser();
            if (null === $rowUser || null === $rowUser->getId()) {
                continue;
            }
            if (!$user->getId()->equals($rowUser->getId())) {
                continue;
            }
            if (null === $role || $membership->getRole() === $role) {
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
        foreach ($this->groups as $group) {
            foreach ($group->getMembers() as $member) {
                if (null !== $member->getId()) {
                    $bag[(string) $member->getId()] = $member;
                }
            }
        }
        return $bag;
    }
}
