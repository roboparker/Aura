<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A user's OAuth connection to a third-party account for API access (#581) —
 * distinct from {@see SsoIdentity} (which is for sign-in). One per (user,
 * provider). Holds the access + refresh tokens (stored as encrypted envelopes;
 * crypto lives in {@see \App\Service\OAuthTokenService}) and the granted
 * scopes, so features like calendar sync (#582) can call the provider's API on
 * the user's behalf.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_connection')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_connection_user_provider', columns: ['user_id', 'provider'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_oauth_connection_user')]
class OAuthConnection
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    /** Encrypted envelope (see OAuthTokenService). */
    #[ORM\Column(type: 'text')]
    private string $accessToken = '';

    /** Encrypted envelope, or null when the provider issued no refresh token. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes = [];

    /**
     * Opaque incremental-sync cursor for pulling calendar changes (#582 Phase
     * B). Null until the first sync; reset when the provider reports it stale.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $calendarSyncToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $calendarSyncedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $provider = '')
    {
        $this->provider = $provider;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function setAccessToken(string $accessToken): static
    {
        $this->accessToken = $accessToken;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): static
    {
        $this->refreshToken = $refreshToken;
        return $this;
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

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @param list<string> $scopes */
    public function setScopes(array $scopes): static
    {
        $this->scopes = $scopes;
        return $this;
    }

    public function getCalendarSyncToken(): ?string
    {
        return $this->calendarSyncToken;
    }

    public function setCalendarSyncToken(?string $calendarSyncToken): static
    {
        $this->calendarSyncToken = $calendarSyncToken;
        return $this;
    }

    public function getCalendarSyncedAt(): ?\DateTimeImmutable
    {
        return $this->calendarSyncedAt;
    }

    public function setCalendarSyncedAt(?\DateTimeImmutable $calendarSyncedAt): static
    {
        $this->calendarSyncedAt = $calendarSyncedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
