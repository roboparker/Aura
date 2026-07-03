<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A social-login identity linked to a user (#267): one row per (provider,
 * subject). A user can link several providers (Google / Microsoft / GitHub),
 * and any linked identity signs them in. `subject` is the provider's stable
 * account id; `email` is a snapshot for display. Unique on (provider, subject)
 * so an external account maps to exactly one Aura user.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sso_identity')]
#[ORM\UniqueConstraint(name: 'uniq_sso_identity_provider_subject', columns: ['provider', 'subject'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_sso_identity_user')]
class SsoIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['sso:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'ssoIdentities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 32)]
    #[Groups(['sso:read'])]
    private string $provider;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['sso:read'])]
    private ?string $email = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['sso:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $provider = '', string $subject = '')
    {
        $this->provider = $provider;
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
