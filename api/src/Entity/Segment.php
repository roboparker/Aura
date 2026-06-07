<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\SegmentRepository;
use App\State\SegmentCreateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named cohort of users used for A/B testing, beta-feature gating, and
 * gradual rollouts. Instance-level (admin-managed) rather than space-scoped:
 * a segment is a property of the whole deployment, like roles or the
 * waitlist toggle.
 *
 * Membership is *dynamic* — a user belongs to a segment when any rule
 * matches, with explicit per-user overrides winning (see
 * {@see \App\Service\SegmentEvaluator} for the precedence). The rules are:
 *   - manual include / exclude rows ({@see SegmentMembership})
 *   - the user's roles intersecting {@see $includedRoles}
 *   - a deterministic percentage {@see $rolloutPercentage} bucket
 *
 * Each segment surfaces as a synthetic role `ROLE_SEGMENT_<KEY>` (see
 * {@see getRole()}). Because membership is dynamic the role isn't stored on
 * the user; {@see \App\Security\SegmentVoter} answers `is_granted()` live and
 * the active role strings are appended to the `/api/me` roles array. So
 * feature-gating anywhere is just `is_granted('ROLE_SEGMENT_BETA_EDITOR')`
 * server-side or `user.roles.includes('ROLE_SEGMENT_BETA_EDITOR')` in the PWA.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            // `key` is settable only on create (segment:create), so the POST
            // denormalisation context unions it with the editable fields.
            denormalizationContext: ['groups' => ['segment:write', 'segment:create']],
            processor: SegmentCreateProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    normalizationContext: ['groups' => ['segment:read']],
    denormalizationContext: ['groups' => ['segment:write']],
    order: ['createdOn' => 'DESC'],
)]
#[ORM\Entity(repositoryClass: SegmentRepository::class)]
#[ORM\Table(name: 'segment')]
#[ORM\UniqueConstraint(name: 'uniq_segment_key', columns: ['segment_key'])]
class Segment
{
    public const MAX_KEY_LENGTH = 64;
    public const MAX_NAME_LENGTH = 255;
    public const ROLE_PREFIX = 'ROLE_SEGMENT_';

    /** Informational labels for what a segment is used for. */
    public const PURPOSE_BETA = 'beta';
    public const PURPOSE_AB_TEST = 'ab_test';
    public const PURPOSE_ROLLOUT = 'rollout';
    public const PURPOSES = [self::PURPOSE_BETA, self::PURPOSE_AB_TEST, self::PURPOSE_ROLLOUT];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['segment:read'])]
    private ?Uuid $id = null;

    /**
     * Stable, code-facing handle ("beta-new-editor"). Lowercase, no
     * underscores — it maps 1:1 to the synthetic role
     * `ROLE_SEGMENT_BETA_NEW_EDITOR` ({@see getRole()}), and the reverse
     * mapping in {@see roleToKey()} relies on the no-underscore rule. Set
     * once on create; read-only on the wire afterwards (like a username).
     */
    #[ORM\Column(name: 'segment_key', length: self::MAX_KEY_LENGTH)]
    #[Assert\NotBlank(message: 'A key is required.')]
    #[Assert\Length(max: self::MAX_KEY_LENGTH)]
    #[Assert\Regex(
        pattern: '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/',
        message: 'Key must be lowercase letters, numbers and single dashes (e.g. "beta-new-editor").',
    )]
    #[Groups(['segment:read', 'segment:create'])]
    private string $key = '';

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'A name is required.')]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups(['segment:read', 'segment:write'])]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 100000)]
    #[Groups(['segment:read', 'segment:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 20, options: ['default' => self::PURPOSE_BETA])]
    #[Assert\Choice(choices: self::PURPOSES, message: 'Unknown segment purpose.')]
    #[Groups(['segment:read', 'segment:write'])]
    private string $purpose = self::PURPOSE_BETA;

    /**
     * Master switch. A disabled segment matches nobody — the voter and
     * evaluator short-circuit on it — so you can dark-launch or kill a
     * rollout without deleting its config or membership rows.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['segment:read', 'segment:write'])]
    private bool $enabled = true;

    /**
     * Percentage (0–100) of users included by a deterministic hash of
     * `key:userId`. Null/0 means "no rollout bucket". Stable per
     * (user, segment): a user who is in at 20% stays in when you raise it
     * to 30%.
     */
    #[ORM\Column(name: 'rollout_percentage', type: 'smallint', nullable: true)]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Rollout must be between {{ min }} and {{ max }}.')]
    #[Groups(['segment:read', 'segment:write'])]
    private ?int $rolloutPercentage = null;

    /**
     * Auto-include users whose runtime roles (User::getRoles, which appends
     * the implicit ROLE_USER) intersect this list — e.g. `["ROLE_ADMIN"]`
     * to expose a beta to every admin. Synthetic segment roles are rejected
     * here (a segment can't be gated on another segment) by the entity
     * validator below.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'included_roles', type: 'json')]
    #[Groups(['segment:read', 'segment:write'])]
    private array $includedRoles = [];

    /**
     * Who created the segment. Nullable + SET NULL so removing a user never
     * orphans the segment config.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['segment:read'])]
    private ?User $createdBy = null;

    /**
     * Manual include / exclude overrides. Read-only via the embedded
     * collection; mutated through {@see \App\Controller\SegmentMemberController}.
     *
     * @var Collection<int, SegmentMembership>
     */
    #[ORM\OneToMany(
        mappedBy: 'segment',
        targetEntity: SegmentMembership::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['joinedAt' => 'ASC'])]
    #[Groups(['segment:read'])]
    private Collection $memberships;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['segment:read'])]
    private \DateTimeImmutable $createdOn;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    #[Groups(['segment:read'])]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->memberships = new ArrayCollection();
    }

    /**
     * The synthetic role a segment grants: `ROLE_SEGMENT_<KEY>` with the
     * key upper-cased and dashes turned into underscores.
     */
    public static function keyToRole(string $key): string
    {
        return self::ROLE_PREFIX . strtoupper(str_replace('-', '_', $key));
    }

    /**
     * Reverse of {@see keyToRole()}. Safe because keys never contain
     * underscores (only single dashes), so `_` unambiguously came from a
     * dash. Returns null when the attribute isn't a segment role.
     */
    public static function roleToKey(string $role): ?string
    {
        if (!str_starts_with($role, self::ROLE_PREFIX)) {
            return null;
        }

        return strtolower(str_replace('_', '-', substr($role, strlen(self::ROLE_PREFIX))));
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    #[Groups(['segment:read'])]
    public function getRole(): string
    {
        return self::keyToRole($this->key);
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

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function setPurpose(string $purpose): static
    {
        $this->purpose = $purpose;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getRolloutPercentage(): ?int
    {
        return $this->rolloutPercentage;
    }

    public function setRolloutPercentage(?int $rolloutPercentage): static
    {
        $this->rolloutPercentage = $rolloutPercentage;
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getIncludedRoles(): array
    {
        return $this->includedRoles;
    }

    /**
     * @param list<string> $includedRoles
     */
    public function setIncludedRoles(array $includedRoles): static
    {
        // Normalise to a clean, de-duplicated, upper-cased list so the
        // runtime intersection in the evaluator is a plain string compare.
        $normalised = [];
        foreach ($includedRoles as $role) {
            $role = strtoupper(trim($role));
            if ('' !== $role && !in_array($role, $normalised, true)) {
                $normalised[] = $role;
            }
        }
        $this->includedRoles = $normalised;
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
     * @return Collection<int, SegmentMembership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
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
     * The manual override mode for a user, or null when there's no manual
     * row. UUID-based so it survives EntityManager::clear().
     */
    public function manualModeFor(?User $user): ?string
    {
        if (null === $user || null === $user->getId()) {
            return null;
        }
        foreach ($this->memberships as $membership) {
            $rowUser = $membership->getUser();
            if (null !== $rowUser && null !== $rowUser->getId() && $user->getId()->equals($rowUser->getId())) {
                return $membership->getMode();
            }
        }
        return null;
    }

    /**
     * Reject segment roles in includedRoles so a segment can never depend on
     * another segment's synthetic role (which the voter, not getRoles,
     * resolves — gating on it here would silently never match).
     */
    #[Assert\Callback]
    public function validateIncludedRoles(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        foreach ($this->includedRoles as $role) {
            if (str_starts_with($role, self::ROLE_PREFIX)) {
                $context->buildViolation('A segment cannot be gated on another segment role.')
                    ->atPath('includedRoles')
                    ->addViolation();
                return;
            }
        }
    }
}
