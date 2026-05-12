<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\PushSubscriptionRepository;
use App\State\PushSubscriptionOwnerProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Web Push subscription registered by the browser via PushManager.
 * Multiple rows per user are expected (one per device + browser
 * combination). The endpoint URL is unique across the whole table —
 * the browser surfaces the same URL for re-subscribes, so a unique
 * index lets the API treat re-registration as an idempotent upsert
 * candidate (today we 409 the duplicate; future iteration could
 * collapse to the existing row).
 *
 * Listing and deletion are scoped to the current user via a Doctrine
 * query extension (see PushSubscriptionUserExtension). Subscription
 * payloads (p256dh + auth keys) are write-only on the API surface so
 * the secrets never echo back in a GET response.
 */
#[ApiResource(
    shortName: 'PushSubscription',
    operations: [
        new GetCollection(
            uriTemplate: '/me/push-subscriptions',
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            uriTemplate: '/me/push-subscriptions',
            security: "is_granted('ROLE_USER')",
            processor: PushSubscriptionOwnerProcessor::class,
        ),
        new Delete(
            uriTemplate: '/me/push-subscriptions/{id}',
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getUser() == user)",
        ),
    ],
    normalizationContext: ['groups' => ['push_subscription:read']],
    denormalizationContext: ['groups' => ['push_subscription:write']],
    order: ['createdAt' => 'DESC'],
)]
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_endpoint', columns: ['endpoint'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_push_subscription_user')]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['push_subscription:read'])]
    private ?Uuid $id = null;

    /**
     * Stamped server-side by PushSubscriptionOwnerProcessor — never
     * trusted from the request payload, same pattern as TaskComment.author
     * and Task.owner.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'text', unique: true)]
    #[Assert\NotBlank(message: 'Subscription endpoint is required.')]
    #[Assert\Url(message: 'Subscription endpoint must be a valid URL.')]
    #[Assert\Length(max: 2048)]
    #[Groups(['push_subscription:read', 'push_subscription:write'])]
    private string $endpoint = '';

    /**
     * Public ECDH key from the browser's subscription.keys.p256dh.
     * Together with `auth` it lets the server encrypt the push payload
     * so even the push service can't read it. Write-only on the API
     * surface to keep the secret out of GET responses.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'p256dh key is required.')]
    #[Groups(['push_subscription:write'])]
    private string $p256dh = '';

    /**
     * Authentication secret from subscription.keys.auth. Same write-only
     * exposure as `p256dh`.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'auth key is required.')]
    #[Groups(['push_subscription:write'])]
    private string $auth = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['push_subscription:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
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

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function setP256dh(string $p256dh): static
    {
        $this->p256dh = $p256dh;
        return $this;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    public function setAuth(string $auth): static
    {
        $this->auth = $auth;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
