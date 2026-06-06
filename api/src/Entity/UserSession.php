<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A browser sign-in session, surfaced in the Settings security panel as the
 * "Active sessions" list. Auth itself is cookie/PHP-session based — this
 * table is a registry keyed by `sha256(session id)` (the raw id is never
 * stored) so the user can see their devices and revoke them.
 *
 * Rows are created/touched by {@see \App\EventListener\SessionRegistryListener}
 * on each authenticated request to the `main` firewall (token-authenticated
 * `/mcp` traffic is excluded — tokens aren't sessions). Revoking sets
 * `revokedAt`; the same listener logs out a session whose row is revoked on
 * its next request.
 */
#[ORM\Entity(repositoryClass: UserSessionRepository::class)]
#[ORM\Table(name: 'user_session')]
#[ORM\UniqueConstraint(name: 'uniq_user_session_hash', columns: ['session_id_hash'])]
#[ORM\Index(columns: ['user_id', 'revoked_at'], name: 'idx_user_session_user')]
class UserSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $sessionIdHash = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getSessionIdHash(): string
    {
        return $this->sessionIdHash;
    }

    public function setSessionIdHash(string $hash): static
    {
        $this->sessionIdHash = $hash;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = null !== $userAgent ? mb_substr($userAgent, 0, 1024) : null;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touch(\DateTimeImmutable $at): static
    {
        $this->lastSeenAt = $at;
        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(): static
    {
        if (null === $this->revokedAt) {
            $this->revokedAt = new \DateTimeImmutable();
        }
        return $this;
    }
}
