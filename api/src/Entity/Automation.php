<?php

namespace App\Entity;

use App\Repository\AutomationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One automation rule on a board (#764).
 *
 * The graph is stored as JSON rather than as rows-per-node: it's read and
 * written whole by the canvas, never queried into, and a node/edge schema would
 * buy nothing but joins. `App\Automation\AutomationGraph` is the only thing
 * that parses it, so the shape has exactly one reader.
 *
 * Not an `ApiResource` — every write has to parse and validate the graph
 * against the registry, which is far clearer in a controller than spread across
 * processors and validators.
 *
 * `triggerEvent` is denormalised from the graph on save so the engine can find
 * candidate rules with an indexed lookup instead of deserialising every rule on
 * the board for every task edit.
 */
#[ORM\Entity(repositoryClass: AutomationRepository::class)]
#[ORM\Table(name: 'automation')]
#[ORM\Index(columns: ['board_id', 'trigger_event', 'enabled'], name: 'idx_automation_board_event')]
class Automation
{
    public const MAX_NAME_LENGTH = 120;

    /** Cap per board — each rule is work on every matching task edit. */
    public const MAX_PER_BOARD = 50;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Board::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Board $board = null;

    /**
     * Denormalised from the board on save, so the access extension and the
     * engine can scope by space without joining through board.
     */
    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Space $space = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name = '';

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

    /**
     * The trigger's event, copied out of the graph on save. Indexed, so a task
     * edit wakes only the rules that could possibly match.
     */
    #[ORM\Column(length: 40)]
    private string $triggerEvent = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $graph = [];

    /**
     * Why the rule was auto-disabled, if it was — an unparseable graph or a
     * runaway rate. Shown in the editor so a silently-off rule isn't a mystery.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

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

    public function getBoard(): ?Board
    {
        return $this->board;
    }

    /** Also stamps the denormalised space, so the two can't drift apart. */
    public function setBoard(?Board $board): self
    {
        $this->board = $board;
        $this->space = $board?->getSpace();

        return $this;
    }

    public function getSpace(): ?Space
    {
        return $this->space;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        if ($enabled) {
            // Re-enabling clears the reason it was switched off; leaving a
            // stale error next to a running rule is worse than no error.
            $this->lastError = null;
        }

        return $this;
    }

    public function getTriggerEvent(): string
    {
        return $this->triggerEvent;
    }

    public function setTriggerEvent(string $triggerEvent): self
    {
        $this->triggerEvent = $triggerEvent;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getGraph(): array
    {
        return $this->graph;
    }

    /** @param array<string, mixed> $graph */
    public function setGraph(array $graph): self
    {
        $this->graph = $graph;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): self
    {
        $this->lastError = $lastError;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): self
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
