<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One person's running conversation with one agent (#827, step 3).
 *
 * **One conversation per (user, agent) pair**, enforced by a unique index. Not
 * per space, and not shared: an agent belongs to a space, but a conversation is
 * someone talking to it, and two colleagues talking to the same agent are
 * having two different conversations. A shared thread would also mean everything
 * one person said becomes context the model sees for everybody else, which is
 * both a privacy surprise and a way to inject instructions into a colleague's
 * session.
 *
 * Flat and chronological, like the {@see Comment} threads — no branching in v1.
 *
 * `space` is denormalised from the agent's membership. It could be walked every
 * time, but it is what both the access check and the billable account resolve
 * from on every message, and an agent's membership is fixed at provisioning.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_conversation')]
#[ORM\UniqueConstraint(name: 'uniq_agent_conversation', columns: ['agent_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_agent_conversation_user')]
#[ORM\Index(columns: ['space_id'], name: 'idx_agent_conversation_space')]
class AgentConversation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /** The agent being talked to — a {@see User} flagged `isAgent`. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $agent = null;

    /** The person doing the talking. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Space $space = null;

    /**
     * @var Collection<int, AgentMessage>
     */
    #[ORM\OneToMany(
        mappedBy: 'conversation',
        targetEntity: AgentMessage::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Drives ordering in a future conversation list; null until first use. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getAgent(): ?User
    {
        return $this->agent;
    }

    public function setAgent(?User $agent): static
    {
        $this->agent = $agent;
        return $this;
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

    public function getSpace(): ?Space
    {
        return $this->space;
    }

    public function setSpace(?Space $space): static
    {
        $this->space = $space;
        return $this;
    }

    /**
     * @return Collection<int, AgentMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(AgentMessage $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }
        $this->lastMessageAt = $message->getCreatedAt();

        return $this;
    }

    public function removeMessage(AgentMessage $message): static
    {
        $this->messages->removeElement($message);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTimeImmutable $at): static
    {
        $this->lastMessageAt = $at;
        return $this;
    }
}
