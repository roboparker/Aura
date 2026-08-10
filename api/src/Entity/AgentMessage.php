<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\ChatMessage;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One turn in an {@see AgentConversation} (#827, step 3).
 *
 * Stores only what a turn *is* — who said it and what they said. Token counts
 * live in the credit ledger, which is the record of what was spent; duplicating
 * them here would create a second set of numbers that could disagree with the
 * bill.
 *
 * `role` deliberately reuses the {@see ChatMessage} vocabulary, so replaying a
 * stored conversation into a provider is a mapping rather than a translation.
 * The system instruction is *not* stored: it is rebuilt from the agent and
 * space on every send, so changing it takes effect immediately instead of
 * leaving old conversations running under an instruction nobody can see.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_message')]
#[ORM\Index(columns: ['conversation_id', 'created_at'], name: 'idx_agent_message_thread')]
class AgentMessage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: AgentConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AgentConversation $conversation = null;

    /** {@see ChatMessage::ROLE_USER} or {@see ChatMessage::ROLE_ASSISTANT}. */
    #[ORM\Column(length: 16)]
    private string $role = ChatMessage::ROLE_USER;

    #[ORM\Column(type: 'text')]
    private string $body = '';

    /**
     * True when the model hit its output ceiling mid-answer. Worth recording
     * rather than inferring: to a reader a truncated answer just looks like the
     * agent trailed off, and there is nothing in the text itself that says
     * otherwise.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $truncated = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getConversation(): ?AgentConversation
    {
        return $this->conversation;
    }

    public function setConversation(?AgentConversation $conversation): static
    {
        $this->conversation = $conversation;
        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function isFromAgent(): bool
    {
        return ChatMessage::ROLE_ASSISTANT === $this->role;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function setTruncated(bool $truncated): static
    {
        $this->truncated = $truncated;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Replay this stored turn back into the provider vocabulary. */
    public function toChatMessage(): ChatMessage
    {
        return ChatMessage::fromStored($this->role, $this->body);
    }

    /**
     * @return array{id: string, role: string, body: string, truncated: bool, createdAt: string}
     */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'role' => $this->role,
            'body' => $this->body,
            'truncated' => $this->truncated,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
