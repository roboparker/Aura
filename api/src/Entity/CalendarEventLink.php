<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Maps an Aura {@see Task} to the event it was pushed to in an external
 * calendar (#582). Phase A is a one-way push of the task owner's dated tasks
 * to their connected Google Calendar, so there is one link per (task,
 * provider); the target account is the task owner's {@see OAuthConnection}.
 *
 * The row is CASCADE-deleted with its task, so the delete-sync job carries the
 * event id in its payload rather than reading it back after the task is gone.
 */
#[ORM\Entity]
#[ORM\Table(name: 'calendar_event_link')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_event_link_task_provider', columns: ['task_id', 'provider'])]
#[ORM\Index(columns: ['task_id'], name: 'idx_calendar_event_link_task')]
class CalendarEventLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Task $task = null;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(length: 255)]
    private string $externalEventId = '';

    #[ORM\Column(length: 255)]
    private string $calendarId = 'primary';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * When we last pushed this event to the provider (#582 Phase D). The pull
     * won't reflect a provider change older than this, so an in-flight Aura
     * edit can't be clobbered by a stale echo.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPushedAt = null;

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

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): static
    {
        $this->task = $task;
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

    public function getExternalEventId(): string
    {
        return $this->externalEventId;
    }

    public function setExternalEventId(string $externalEventId): static
    {
        $this->externalEventId = $externalEventId;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCalendarId(): string
    {
        return $this->calendarId;
    }

    public function setCalendarId(string $calendarId): static
    {
        $this->calendarId = $calendarId;
        return $this;
    }

    public function getLastPushedAt(): ?\DateTimeImmutable
    {
        return $this->lastPushedAt;
    }

    public function markPushed(): static
    {
        $this->lastPushedAt = new \DateTimeImmutable();
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
