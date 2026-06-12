<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserUsageSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A nightly point-in-time rollup of one user's resource usage — the table
 * queried for cost estimation / pricing.
 *
 * One row per (user, captured day). `apiCalls` / `mcpCalls` are copied from
 * the day's {@see UserUsageCounter} buckets; `diskBytes` is the SUM of the
 * user's media_object byte sizes at capture time; `dbBytesEst` is a logical
 * size estimate (sum of octet_length over the big content columns the user
 * owns, plus a per-row overhead constant) — accurate enough to rank users by
 * footprint without an expensive physical-page scan. Written by
 * {@see \App\Service\UsageSnapshotBuilder}.
 */
#[ORM\Entity(repositoryClass: UserUsageSnapshotRepository::class)]
#[ORM\Table(name: 'user_usage_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_user_usage_snapshot', columns: ['user_id', 'captured_on'])]
#[ORM\Index(columns: ['captured_on'], name: 'idx_user_usage_snapshot_captured_on')]
class UserUsageSnapshot
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
    private \DateTimeImmutable $capturedOn;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $diskBytes = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $dbBytesEst = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $apiCalls = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $mcpCalls = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->capturedOn = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
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

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getCapturedOn(): \DateTimeImmutable
    {
        return $this->capturedOn;
    }

    public function setCapturedOn(\DateTimeImmutable $capturedOn): static
    {
        $this->capturedOn = $capturedOn;
        return $this;
    }

    public function getDiskBytes(): int
    {
        return $this->diskBytes;
    }

    public function setDiskBytes(int $diskBytes): static
    {
        $this->diskBytes = $diskBytes;
        return $this;
    }

    public function getDbBytesEst(): int
    {
        return $this->dbBytesEst;
    }

    public function setDbBytesEst(int $dbBytesEst): static
    {
        $this->dbBytesEst = $dbBytesEst;
        return $this;
    }

    public function getApiCalls(): int
    {
        return $this->apiCalls;
    }

    public function setApiCalls(int $apiCalls): static
    {
        $this->apiCalls = $apiCalls;
        return $this;
    }

    public function getMcpCalls(): int
    {
        return $this->mcpCalls;
    }

    public function setMcpCalls(int $mcpCalls): static
    {
        $this->mcpCalls = $mcpCalls;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
