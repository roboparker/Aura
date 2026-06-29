<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\ApiTokenRepository;
use App\Security\Access\AccessPolicy;
use App\State\ApiTokenCreateProcessor;
use App\State\ApiTokenDeleteProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Personal access token bound to a single user, used to authenticate
 * MCP (and any future programmatic) callers via `Authorization: Bearer`.
 *
 * Plaintext is shown to the human exactly once at creation (in the POST
 * response under `plainToken`) — only the sha256 hash is persisted, so
 * a database leak cannot be replayed against the API. Hashing matches
 * the pattern already established by {@see PasswordResetToken} and
 * {@see UserInvite}, just with a different prefix and longer entropy.
 *
 * A token acts AS its owner, optionally narrowed by `accessPolicy` — the
 * same {@see AccessPolicy} shape used for admin-impersonation consent
 * (per-category + per-item none/view/edit). `null` means "unrestricted":
 * the token can do exactly what its owner can in the app. The policy is
 * enforced uniformly across the REST API + MCP by the shared engine
 * (App\Security\Access\ActorPolicyResolver + AccessPolicyListener /
 * AccessPolicyItemScope on REST; the MCP controller on tool dispatch).
 */
#[ApiResource(
    shortName: 'ApiToken',
    operations: [
        new GetCollection(
            uriTemplate: '/api-tokens',
            security: "is_granted('ROLE_USER')",
        ),
        // Item Get pins the resource IRI to the hyphenated `/api-tokens/{id}`
        // template so the `@id` in responses matches the Delete route (without
        // it, API Platform falls back to the default `/api_tokens/{id}` and
        // DELETE 405s).
        new Get(
            uriTemplate: '/api-tokens/{id}',
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getUser() == user)",
        ),
        new Post(
            uriTemplate: '/api-tokens',
            security: "is_granted('ROLE_USER')",
            processor: ApiTokenCreateProcessor::class,
            // POST is the only moment the plaintext bearer is visible.
            // Subsequent GETs use the stricter `api_token:read` group.
            normalizationContext: ['groups' => ['api_token:read', 'api_token:create']],
        ),
        // Editing is limited to the human-managed fields (name / accessPolicy /
        // expiresAt via the `api_token:write` group). The secret itself is
        // immutable — there's no way to rotate the plaintext in place; revoke
        // and re-create instead. `validateAccessPolicy()` re-runs on update.
        new Patch(
            uriTemplate: '/api-tokens/{id}',
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getUser() == user)",
        ),
        new Delete(
            uriTemplate: '/api-tokens/{id}',
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getUser() == user)",
            processor: ApiTokenDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['api_token:read']],
    denormalizationContext: ['groups' => ['api_token:write']],
    order: ['createdAt' => 'DESC'],
)]
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_token')]
#[ORM\Index(columns: ['user_id'], name: 'idx_api_token_user')]
#[ORM\Index(columns: ['token_hash'], name: 'idx_api_token_hash')]
class ApiToken
{
    public const PLAINTEXT_PREFIX = 'madori_pat_';

    public const MAX_NAME_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['api_token:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * sha256 of the full plaintext (including the `madori_pat_` prefix).
     * 64-char hex column matches the existing PasswordResetToken layout.
     */
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'Token name is required.')]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups(['api_token:read', 'api_token:write'])]
    private string $name = '';

    /**
     * Optional capability narrowing for this token, in the shared
     * {@see AccessPolicy} shape: `{ categories: { tasks: 'view', … },
     * items: { project: { '<uuid>': 'edit' } } }`. `null` = unrestricted
     * (acts exactly as the owner). Validated by {@see validateAccessPolicy()}.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['api_token:read', 'api_token:write'])]
    private ?array $accessPolicy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['api_token:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['api_token:read', 'api_token:write'])]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['api_token:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Non-null for a SPACE-SCOPED key (#space-roles): the key is confined to
     * this space and its permissions come from {@see $roles} (not
     * accessPolicy / not the creator's full access). Null = a personal token
     * (acts as its owner, narrowed by accessPolicy) — the legacy behaviour.
     */
    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['api_token:read'])]
    private ?Space $space = null;

    /**
     * Roles granted to a space-scoped key — its effective permissions are the
     * union (no roles ⇒ the key can do nothing). Ignored for personal tokens.
     *
     * @var Collection<int, SpaceRole>
     */
    #[ORM\ManyToMany(targetEntity: SpaceRole::class)]
    #[ORM\JoinTable(name: 'api_token_role')]
    #[Groups(['api_token:read'])]
    private Collection $roles;

    /**
     * Transient. Populated by the create processor with the plaintext
     * bearer (`madori_pat_…`) so it can be returned exactly once in the
     * POST response. Never persisted, never read from a fresh entity.
     */
    #[Groups(['api_token:create'])]
    private ?string $plainToken = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->roles = new ArrayCollection();
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

    public function isScoped(): bool
    {
        return null !== $this->space;
    }

    /**
     * @return Collection<int, SpaceRole>
     */
    public function getRoles(): Collection
    {
        return $this->roles;
    }

    public function addRole(SpaceRole $role): static
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
        }

        return $this;
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function setPlainToken(?string $plainToken): static
    {
        $this->plainToken = $plainToken;
        return $this;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): static
    {
        $this->tokenHash = $tokenHash;
        return $this;
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

    /** @return array<string, mixed>|null */
    public function getAccessPolicy(): ?array
    {
        return $this->accessPolicy;
    }

    /** @param array<string, mixed>|null $accessPolicy */
    public function setAccessPolicy(?array $accessPolicy): static
    {
        $this->accessPolicy = $accessPolicy;
        return $this;
    }

    /**
     * The token's capability narrowing as a runtime {@see AccessPolicy}, or
     * null when unrestricted (acts exactly as its owner).
     */
    public function toAccessPolicy(): ?AccessPolicy
    {
        if (null === $this->accessPolicy) {
            return null;
        }
        $categories = $this->accessPolicy['categories'] ?? [];
        $items = $this->accessPolicy['items'] ?? [];

        return new AccessPolicy(
            is_array($categories) ? $categories : [],
            is_array($items) ? $items : [],
        );
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function touch(?\DateTimeImmutable $when = null): void
    {
        $this->lastUsedAt = $when ?? new \DateTimeImmutable();
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }
        $now ??= new \DateTimeImmutable();
        return $this->expiresAt <= $now;
    }

    /**
     * Validates the optional accessPolicy blob: a `{categories, items}` object
     * over the known categories / item types / none-view-edit levels. Runs on
     * POST via the validator; null (unrestricted) is always valid.
     */
    #[Assert\Callback]
    public function validateAccessPolicy(ExecutionContextInterface $context): void
    {
        if (null === $this->accessPolicy) {
            return;
        }
        $categories = $this->accessPolicy['categories'] ?? [];
        $items = $this->accessPolicy['items'] ?? [];
        if (!is_array($categories) || !is_array($items)) {
            $context->buildViolation('accessPolicy must be an object with categories and items.')
                ->atPath('accessPolicy')->addViolation();
            return;
        }
        foreach ($categories as $cat => $level) {
            if (
                !is_string($cat) || !in_array($cat, AccessPolicy::CATEGORIES, true)
                || !is_string($level) || !in_array($level, AccessPolicy::LEVELS, true)
            ) {
                $context->buildViolation('accessPolicy.categories has an unknown category or level.')
                    ->atPath('accessPolicy')->addViolation();
                return;
            }
        }
        foreach ($items as $type => $map) {
            if (!is_string($type) || !in_array($type, AccessPolicy::ITEM_TYPES, true) || !is_array($map)) {
                $context->buildViolation('accessPolicy.items has an unknown item type.')
                    ->atPath('accessPolicy')->addViolation();
                return;
            }
            foreach ($map as $id => $level) {
                if (
                    !is_string($id) || !Uuid::isValid($id)
                    || !is_string($level) || !in_array($level, AccessPolicy::LEVELS, true)
                ) {
                    $context->buildViolation('accessPolicy.items entries must be UUID → none|view|edit.')
                        ->atPath('accessPolicy')->addViolation();
                    return;
                }
            }
        }
    }
}
