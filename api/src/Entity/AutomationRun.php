<?php

namespace App\Entity;

use App\Repository\AutomationRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One execution of one rule against one task (#764).
 *
 * Exists so "why did my task change?" and "why didn't my rule fire?" are
 * answerable. Both are unanswerable without a per-step record, and both are the
 * first thing anyone asks of a rules engine.
 *
 * Rows are pruned on the shared retention schedule — this is a debugging aid,
 * not an audit log, and an unbounded one would dwarf the tasks it describes.
 */
#[ORM\Entity(repositoryClass: AutomationRunRepository::class)]
#[ORM\Table(name: 'automation_run')]
#[ORM\Index(columns: ['automation_id', 'ran_at'], name: 'idx_automation_run_rule_time')]
#[ORM\Index(columns: ['task_id'], name: 'idx_automation_run_task')]
class AutomationRun
{
    /** At least one action did something. */
    public const STATUS_APPLIED = 'applied';

    /** The trigger matched, but every branch was gated off by a condition. */
    public const STATUS_NO_MATCH = 'no_match';

    /** At least one step errored. */
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Automation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Automation $automation = null;

    /**
     * Nullable and SET NULL: a run describing a since-deleted task is still
     * worth reading, and losing the history because someone tidied up would
     * defeat the point.
     */
    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $task = null;

    /** Denormalised, so a run still names its task after the task is gone. */
    #[ORM\Column(length: 255)]
    private string $taskTitle = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_NO_MATCH;

    /**
     * How deep into an automation chain this run was. A non-zero depth means
     * the change was itself made by a rule — the first thing to look at when
     * rules appear to be fighting each other.
     */
    #[ORM\Column(type: 'integer')]
    private int $depth = 0;

    /**
     * Per-step outcome: `{node, kind, type, outcome, detail}`.
     *
     * @var list<array{node: string, kind: string, type: string, outcome: string, detail: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $steps = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $ranAt;

    public function __construct()
    {
        $this->ranAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getAutomation(): ?Automation
    {
        return $this->automation;
    }

    public function setAutomation(?Automation $automation): self
    {
        $this->automation = $automation;

        return $this;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    /** Also snapshots the title, so the run survives the task's deletion. */
    public function setTask(?Task $task): self
    {
        $this->task = $task;
        if (null !== $task) {
            $this->taskTitle = mb_substr($task->getTitle(), 0, 255);
        }

        return $this;
    }

    public function getTaskTitle(): string
    {
        return $this->taskTitle;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setDepth(int $depth): self
    {
        $this->depth = $depth;

        return $this;
    }

    /** @return list<array{node: string, kind: string, type: string, outcome: string, detail: string}> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /** @param list<array{node: string, kind: string, type: string, outcome: string, detail: string}> $steps */
    public function setSteps(array $steps): self
    {
        $this->steps = $steps;

        return $this;
    }

    public function getRanAt(): \DateTimeImmutable
    {
        return $this->ranAt;
    }
}
