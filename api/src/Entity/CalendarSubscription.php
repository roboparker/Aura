<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A provider change-notification channel for one {@see OAuthConnection} (#582
 * Phase D). One per connection. Holds the provider's channel/subscription
 * handle, the shared `secret` we authenticate callbacks with, and the expiry we
 * renew before. CASCADE-deleted with its connection (we also best-effort tell
 * the provider to stop when the user disconnects).
 */
#[ORM\Entity]
#[ORM\Table(name: 'calendar_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_subscription_connection', columns: ['connection_id'])]
#[ORM\Index(columns: ['channel_id'], name: 'idx_calendar_subscription_channel')]
class CalendarSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: OAuthConnection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OAuthConnection $connection = null;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(length: 255)]
    private string $channelId = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resourceId = null;

    #[ORM\Column(length: 128)]
    private string $secret = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $provider = '')
    {
        $this->provider = $provider;
        $this->expiresAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getConnection(): ?OAuthConnection
    {
        return $this->connection;
    }

    public function setConnection(?OAuthConnection $connection): static
    {
        $this->connection = $connection;
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

    public function getChannelId(): string
    {
        return $this->channelId;
    }

    public function setChannelId(string $channelId): static
    {
        $this->channelId = $channelId;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function setResourceId(?string $resourceId): static
    {
        $this->resourceId = $resourceId;
        return $this;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): static
    {
        $this->secret = $secret;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
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
