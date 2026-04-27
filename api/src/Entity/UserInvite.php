<?php

namespace App\Entity;

use App\Repository\UserInviteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Pending invitation for an email address to create an Aura account. There
 * is at most one UserInvite per email — repeat invites add additional
 * GroupInvite rows under the existing UserInvite rather than starting a
 * parallel one. That way a single signup email and token covers every
 * group the invitee has been asked to join, and only one person can
 * "claim" the invite when they sign up.
 *
 * The plain token is emailed and never persisted — only its sha256 hash
 * is stored, mirroring PasswordResetToken. Not exposed as an API Platform
 * resource; access flows through UserGroupMemberController and
 * UserInviteController so email lookups, expiry, and ownership checks
 * stay in one place.
 */
#[ORM\Entity(repositoryClass: UserInviteRepository::class)]
#[ORM\Table(name: 'user_invite')]
#[ORM\UniqueConstraint(name: 'uniq_user_invite_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_user_invite_token_hash', columns: ['token_hash'])]
#[ORM\Index(columns: ['token_hash'], name: 'idx_user_invite_token_hash')]
class UserInvite
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    /**
     * Per-group rows under this invite. Cascading remove so revoking the
     * UserInvite cleans up its join rows; orphanRemoval so detaching a
     * GroupInvite from `groupInvites` deletes it.
     *
     * @var Collection<int, GroupInvite>
     */
    #[ORM\OneToMany(
        mappedBy: 'userInvite',
        targetEntity: GroupInvite::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $groupInvites;

    public function __construct(
        string $email,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->email = $email;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->groupInvites = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function markAccepted(): void
    {
        $this->acceptedAt = new \DateTimeImmutable();
    }

    public function isPending(\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        return null === $this->acceptedAt && $this->expiresAt > $now;
    }

    /**
     * @return Collection<int, GroupInvite>
     */
    public function getGroupInvites(): Collection
    {
        return $this->groupInvites;
    }
}
