<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserUsageCounterRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A live per-user usage counter, bucketed by UTC day and metric.
 *
 * One row per (user, day, metric); the count is incremented with a single
 * UPSERT keyed by the unique constraint (see {@see \App\Service\UsageRecorder}).
 * `metric` is one of UsageRecorder::METRIC_* (`api_call`, `mcp_call`). Writes
 * happen off the request latency path — at kernel.terminate for HTTP API
 * calls, and at the MCP tool-dispatch chokepoint for tool calls — so we never
 * pay one INSERT per request. The nightly {@see \App\Service\UsageSnapshotBuilder}
 * rolls these up into {@see UserUsageSnapshot} rows; old counter rows are
 * pruned by retention.
 */
#[ORM\Entity(repositoryClass: UserUsageCounterRepository::class)]
#[ORM\Table(name: 'user_usage_counter')]
#[ORM\UniqueConstraint(name: 'uniq_user_usage_counter', columns: ['user_id', 'day', 'metric'])]
#[ORM\Index(columns: ['day'], name: 'idx_user_usage_counter_day')]
class UserUsageCounter
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(length: 16)]
    private string $metric = '';

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $count = 0;

    public function __construct()
    {
        $this->day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
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

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function setDay(\DateTimeImmutable $day): static
    {
        $this->day = $day;
        return $this;
    }

    public function getMetric(): string
    {
        return $this->metric;
    }

    public function setMetric(string $metric): static
    {
        $this->metric = $metric;
        return $this;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): static
    {
        $this->count = $count;
        return $this;
    }
}
