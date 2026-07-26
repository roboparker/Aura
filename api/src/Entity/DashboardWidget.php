<?php

namespace App\Entity;

use App\Repository\DashboardWidgetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One widget placed on a space's dashboard (#759).
 *
 * There is deliberately no parent "Dashboard" entity: a space's dashboard *is*
 * its set of widgets, and a parent row would hold nothing but a FK back to the
 * space.
 *
 * Not an `ApiResource`. Every write has to validate `type` against the widget
 * registry and hand `config` to that type for checking, and reorders arrive as
 * a batch — all of which reads far more clearly as
 * {@see \App\Controller\DashboardController} than as a stack of state
 * processors. Reads are permission-filtered per widget there too.
 *
 * `config` is user-supplied JSON. It is validated by the widget definition on
 * write and never interpolated into SQL by anything that consumes it.
 */
#[ORM\Entity(repositoryClass: DashboardWidgetRepository::class)]
#[ORM\Table(name: 'dashboard_widget')]
#[ORM\Index(columns: ['space_id', 'position'], name: 'idx_dashboard_widget_space_position')]
class DashboardWidget
{
    /** Grid is four columns wide; a widget spans one to four of them. */
    public const MIN_WIDTH = 1;
    public const MAX_WIDTH = 4;

    /** Height in row units. Two is roughly a chart; one is a stat tile. */
    public const MIN_HEIGHT = 1;
    public const MAX_HEIGHT = 4;

    /**
     * Cap per space. Not a technical limit — each widget is a query, and a
     * dashboard with a hundred of them is a self-inflicted outage.
     */
    public const MAX_PER_SPACE = 40;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Space $space = null;

    /**
     * The widget definition's wire key, e.g. `analytics.metric`.
     *
     * Intentionally a plain string rather than an enum: the whole point of the
     * registry is that a future module can add a type without this column (or
     * a migration) knowing about it. A row whose type is no longer registered
     * renders as a placeholder instead of breaking the page.
     */
    #[ORM\Column(length: 64)]
    private string $type = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    #[ORM\Column(type: 'integer')]
    private int $width = 2;

    #[ORM\Column(type: 'integer')]
    private int $height = 2;

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

    public function getSpace(): ?Space
    {
        return $this->space;
    }

    public function setSpace(?Space $space): self
    {
        $this->space = $space;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    /** Clamped rather than rejected — a nonsense size is a bug, not an attack. */
    public function setWidth(int $width): self
    {
        $this->width = max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width));

        return $this;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setHeight(int $height): self
    {
        $this->height = max(self::MIN_HEIGHT, min(self::MAX_HEIGHT, $height));

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
