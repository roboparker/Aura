<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CancellationFeedbackRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * "Why are you leaving?" churn survey, captured at a cancellation moment.
 *
 * One row per cancellation event across three contexts: account deactivation,
 * account deletion, and subscription (membership) cancellation. The reason is a
 * required pick from {@see REASONS}; the free-text comment is optional. Written
 * server-side only ({@see \App\Service\CancellationFeedbackRecorder}) — it is
 * not an API resource.
 *
 * Both the user and the space FKs are nullable + SET NULL so the row survives
 * the very action that created it: account deletion removes the user (the FK
 * nulls out, anonymizing the feedback), and a space deletion nulls its FK too.
 */
#[ORM\Entity(repositoryClass: CancellationFeedbackRepository::class)]
#[ORM\Table(name: 'cancellation_feedback')]
#[ORM\Index(columns: ['user_id'], name: 'idx_cancellation_feedback_user')]
#[ORM\Index(columns: ['space_id'], name: 'idx_cancellation_feedback_space')]
#[ORM\Index(columns: ['context'], name: 'idx_cancellation_feedback_context')]
class CancellationFeedback
{
    public const CONTEXT_ACCOUNT_DEACTIVATION = 'account_deactivation';
    public const CONTEXT_ACCOUNT_DELETION = 'account_deletion';
    public const CONTEXT_SUBSCRIPTION_CANCELLATION = 'subscription_cancellation';

    /** @var list<string> */
    public const CONTEXTS = [
        self::CONTEXT_ACCOUNT_DEACTIVATION,
        self::CONTEXT_ACCOUNT_DELETION,
        self::CONTEXT_SUBSCRIPTION_CANCELLATION,
    ];

    public const REASON_TOO_EXPENSIVE = 'too_expensive';
    public const REASON_MISSING_FEATURES = 'missing_features';
    public const REASON_NOT_USING = 'not_using';
    public const REASON_TOO_COMPLICATED = 'too_complicated';
    public const REASON_SWITCHING = 'switching';
    public const REASON_TECHNICAL_ISSUES = 'technical_issues';
    public const REASON_OTHER = 'other';

    /**
     * The allowed survey reasons. Kept in sync with the PWA's mirror in
     * `pwa/lib/cancellationReasons.ts`.
     *
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_TOO_EXPENSIVE,
        self::REASON_MISSING_FEATURES,
        self::REASON_NOT_USING,
        self::REASON_TOO_COMPLICATED,
        self::REASON_SWITCHING,
        self::REASON_TECHNICAL_ISSUES,
        self::REASON_OTHER,
    ];

    /** Max length of the free-text comment (trimmed past this). */
    public const MAX_COMMENT_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 32)]
    private string $context;

    #[ORM\Column(length: 32)]
    private string $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    /**
     * Who cancelled (audit only). SET NULL so account deletion — itself a
     * cancellation context — leaves the feedback behind, anonymized.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /**
     * The space whose subscription was cancelled (subscription context only;
     * null for account contexts). SET NULL so a later space deletion keeps the
     * feedback row.
     */
    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(name: 'space_id', nullable: true, onDelete: 'SET NULL')]
    private ?Space $space = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $context, string $reason)
    {
        $this->context = $context;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function isValidContext(string $context): bool
    {
        return in_array($context, self::CONTEXTS, true);
    }

    public static function isValidReason(string $reason): bool
    {
        return in_array($reason, self::REASONS, true);
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
