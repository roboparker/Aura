<?php

namespace App\Entity;

use App\Repository\FeedbackVoteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One user's vote on one feedback ticket. Not an API resource — votes
 * are mutated only through {@see \App\Controller\FeedbackVoteController}
 * ("POST /feedback/{id}/vote"), which enforces the one-vote-per-ticket
 * toggle, and read back in aggregate by {@see \App\State\FeedbackAggregateProvider}.
 *
 * {@see $value} is +1 (up) or -1 (down); there's no zero row — clearing a
 * vote deletes the row. The unique constraint on (feedback, user)
 * guarantees a single vote per user per ticket. Both FKs cascade on
 * delete, so removing a ticket or a user reaps their votes.
 */
#[ORM\Entity(repositoryClass: FeedbackVoteRepository::class)]
#[ORM\Table(name: 'feedback_vote')]
#[ORM\UniqueConstraint(name: 'uniq_feedback_vote', columns: ['feedback_id', 'user_id'])]
#[ORM\Index(columns: ['feedback_id'], name: 'idx_feedback_vote_feedback')]
class FeedbackVote
{
    public const VALUE_UP = 1;
    public const VALUE_DOWN = -1;
    public const VALUES = [self::VALUE_UP, self::VALUE_DOWN];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Feedback::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Feedback $feedback = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'smallint')]
    private int $value = self::VALUE_UP;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $votedAt;

    public function __construct()
    {
        $this->votedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getFeedback(): ?Feedback
    {
        return $this->feedback;
    }

    public function setFeedback(?Feedback $feedback): static
    {
        $this->feedback = $feedback;
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

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function getVotedAt(): \DateTimeImmutable
    {
        return $this->votedAt;
    }
}
