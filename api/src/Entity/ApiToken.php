<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\ApiTokenRepository;
use App\State\ApiTokenCreateProcessor;
use App\State\ApiTokenDeleteProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

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
 * `scopes` is a free-form JSON array. The current implementation treats
 * the empty array as "all MCP tools allowed"; future iterations can
 * narrow per-tool access without a schema change.
 */
#[ApiResource(
    shortName: 'ApiToken',
    operations: [
        new GetCollection(
            uriTemplate: '/api-tokens',
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            uriTemplate: '/api-tokens',
            security: "is_granted('ROLE_USER')",
            processor: ApiTokenCreateProcessor::class,
            // POST is the only moment the plaintext bearer is visible.
            // Subsequent GETs use the stricter `api_token:read` group.
            normalizationContext: ['groups' => ['api_token:read', 'api_token:create']],
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
    public const PLAINTEXT_PREFIX = 'aura_pat_';

    public const MAX_NAME_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['api_token:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * sha256 of the full plaintext (including the `aura_pat_` prefix).
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
     * Allow-list of MCP tool names the token may invoke. Empty array means
     * "all tools" — the common case for personal tokens. Stored as JSON so
     * granular scope work later doesn't need a schema migration.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['api_token:read', 'api_token:write'])]
    private array $scopes = [];

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
     * Transient. Populated by the create processor with the plaintext
     * bearer (`aura_pat_…`) so it can be returned exactly once in the
     * POST response. Never persisted, never read from a fresh entity.
     */
    #[Groups(['api_token:create'])]
    private ?string $plainToken = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
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

    /** @return string[] */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @param string[] $scopes */
    public function setScopes(array $scopes): static
    {
        $this->scopes = array_values(array_unique(array_filter(
            $scopes,
            static fn (string $v) => '' !== $v,
        )));
        return $this;
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
     * True when the token's allow-list permits the named tool. An empty
     * scope list means "no restriction" — see the property docblock.
     */
    public function allowsTool(string $tool): bool
    {
        if ([] === $this->scopes) {
            return true;
        }
        return in_array($tool, $this->scopes, true);
    }
}
