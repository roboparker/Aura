<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\CustomField\CustomFieldKind;
use App\Repository\CustomFieldDefinitionRepository;
use App\State\CustomFieldDefinitionUpdateProcessor;
use App\State\CustomFieldVisibilityProvider;
use App\Validator\ValidCustomFieldDefinition;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-board custom field definition. Widened in #227 from the flat
 * 5-type enum to a (kind, subtype, config) triple backed by a strategy
 * registry — see {@see App\CustomField\Type\CustomFieldTypeInterface}.
 *
 * Storage shape:
 *   - `kind`     — top-level family (boolean|text|numeric|date|select|reference)
 *   - `subtype`  — specialisation within the kind (e.g. `money` under `numeric`)
 *   - `config`   — per-kind config payload (options, min/max, currency, multi, …)
 *   - `footer`   — optional `{kind, label?}` aggregation descriptor
 *   - `nullable` — whether a missing value is legal for this field
 *
 * Per-kind config + footer validation runs through
 * {@see ValidCustomFieldDefinition} (class-level constraint), which
 * dispatches into the strategy. Per-value validation lives on Task's
 * {@see App\Validator\ValidCustomFieldValues}.
 *
 * Owned by a Space (#custom-fields-space): the field schema is defined
 * once per space and shared. Boards opt in to the fields they want via
 * a {@see Board::$customFieldDefinitions} many-to-many, so each board's
 * task list shows only its chosen subset. Read + write (create/edit/delete)
 * are open to any space member for now.
 */
#[ApiResource(
    shortName: 'CustomFieldDefinition',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            provider: CustomFieldVisibilityProvider::class,
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user) and is_granted('space.custom_fields.create', object)))",
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.custom_fields.read', object)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.custom_fields.update', object)))",
            processor: CustomFieldDefinitionUpdateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.custom_fields.delete', object)))",
        ),
    ],
    normalizationContext: ['groups' => ['custom_field_definition:read']],
    denormalizationContext: ['groups' => ['custom_field_definition:write']],
    order: ['position' => 'ASC', 'createdAt' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact', 'boards' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['position'], arguments: ['orderParameterName' => 'order'])]
#[ORM\Entity(repositoryClass: CustomFieldDefinitionRepository::class)]
#[ORM\Table(name: 'custom_field_definition')]
#[ORM\Index(columns: ['space_id'], name: 'idx_cfd_space')]
#[ORM\Index(columns: ['space_id', 'position'], name: 'idx_cfd_space_position')]
#[ORM\UniqueConstraint(name: 'uniq_cfd_space_name', columns: ['space_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\Loggable(logEntryClass: ActivityLog::class)]
#[UniqueEntity(
    fields: ['space', 'name'],
    message: 'A field with this name already exists in this space.',
)]
#[ValidCustomFieldDefinition]
class CustomFieldDefinition implements CustomFieldDefinitionInterface
{
    /**
     * Legacy flat-type constants from the pre-#227 enum. The columns
     * are gone, but tests and the migration backfill still spell field
     * shapes using these labels. Kept as a translation table for
     * {@see setType()} / {@see fromLegacyType()}; do not introduce new
     * call sites.
     */
    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_DROPDOWN = 'dropdown';
    public const TYPE_CHECKBOX = 'checkbox';

    public const MAX_NAME_LENGTH = 80;

    public const VISIBILITY_LIST = 'list';
    public const VISIBILITY_BOARD = 'board';
    public const VISIBILITY_CALENDAR = 'calendar';
    /** Legacy single-value default meaning list + board (still accepted on read). */
    public const VISIBILITY_BOTH = 'both';

    /** @var list<string> */
    public const VISIBILITIES = [
        self::VISIBILITY_LIST,
        self::VISIBILITY_BOARD,
        self::VISIBILITY_BOTH,
    ];

    /**
     * The independent surfaces a field's value can show on. Per-board
     * visibility (#custom-fields-board) is a comma-joined SET of these.
     *
     * @var list<string>
     */
    public const SURFACES = [
        self::VISIBILITY_LIST,
        self::VISIBILITY_BOARD,
        self::VISIBILITY_CALENDAR,
    ];

    /**
     * Parse a stored visibility value — legacy `both`, a single surface, or a
     * comma-joined set — into the list of surfaces it shows on.
     *
     * @return list<string>
     */
    public static function visibilitySurfaces(string $visibility): array
    {
        if (self::VISIBILITY_BOTH === $visibility) {
            return [self::VISIBILITY_LIST, self::VISIBILITY_BOARD];
        }
        $out = [];
        foreach (explode(',', $visibility) as $surface) {
            $surface = trim($surface);
            if ('' !== $surface && in_array($surface, self::SURFACES, true)) {
                $out[] = $surface;
            }
        }

        return $out;
    }

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['custom_field_definition:read'])]
    private ?Uuid $id = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    // Versioned so the audit log records which space the field belonged to —
    // the change-log endpoint keys on this to recover the history of fields
    // that have since been deleted (their log rows outlive the row itself).
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private ?Space $space = null;

    /**
     * Boards that show this field on their tasks. Inverse side — the join
     * table is owned by {@see Board::$customFieldDefinitions}.
     *
     * @var Collection<int, Board>
     */
    #[ORM\ManyToMany(targetEntity: Board::class, mappedBy: 'customFieldDefinitions')]
    private Collection $boards;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'Field name is required.')]
    #[Assert\Length(
        max: self::MAX_NAME_LENGTH,
        maxMessage: 'Field name cannot be longer than {{ limit }} characters.',
    )]
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private string $name = '';

    /**
     * Top-level family — one of {@see CustomFieldKind}'s values.
     */
    #[ORM\Column(length: 16)]
    #[Assert\Choice(
        callback: [CustomFieldKind::class, 'values'],
        message: 'Kind must be one of: {{ choices }}.',
    )]
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private string $kind = CustomFieldKind::TEXT->value;

    /**
     * Specialisation within the kind. The (kind, subtype) pair is the
     * registry lookup key for the concrete strategy.
     */
    #[ORM\Column(length: 24)]
    #[Assert\NotBlank(message: 'Subtype is required.')]
    #[Assert\Length(max: 24)]
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private string $subtype = 'text';

    /**
     * Per-kind configuration payload. Shape is owned by the strategy
     * for the (kind, subtype) pair — see CustomFieldTypeInterface
     * docblock.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private array $config = [];

    /**
     * Optional footer aggregation descriptor `{kind, label?}`. Null
     * means no footer row for this field; the kind, when present,
     * must be one supported by the strategy (enforced in
     * {@see ValidCustomFieldDefinition}).
     *
     * @var array{kind: string, label?: string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private ?array $footer = null;

    /**
     * Whether a missing or null value is acceptable for this field.
     * `false` means the task validator enforces presence at save time.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private bool $nullable = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Position cannot be negative.')]
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private int $position = 0;

    /**
     * Default visibility — where the field is shown to readers: the board
     * task list (`list`), the Kanban board cards (`board`), or both. Visibility
     * is a PER-PROJECT choice now (#custom-fields-board): a {@see
     * BoardFieldVisibility} row overrides this default for a given board,
     * and {@see \App\State\CustomFieldVisibilityProvider} injects the effective
     * value into the read when the field is fetched in a board context
     * (`?boards={iri}`). This column is the fallback when no override exists.
     * Read-only over the API — set per-board, not on the definition. The task
     * detail drawer always shows every field regardless.
     */
    #[ORM\Column(length: 16, options: ['default' => self::VISIBILITY_BOTH])]
    #[Assert\Choice(
        choices: self::VISIBILITIES,
        message: 'Visibility must be one of: {{ choices }}.',
    )]
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read'])]
    private string $visibility = self::VISIBILITY_BOTH;

    /**
     * Legacy `required` column — kept as a denormalised mirror of
     * `!nullable` for downstream consumers (Doctrine repository
     * findBy queries against `required`, audit log diffs) that
     * pre-date the column rename. Wire surface is `nullable` only;
     * a follow-up migration can drop this column once nothing
     * reads it.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $required = false;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['custom_field_definition:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->boards = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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
     * @return Collection<int, Board>
     */
    public function getBoards(): Collection
    {
        return $this->boards;
    }

    public function addBoard(Board $board): static
    {
        if (!$this->boards->contains($board)) {
            $this->boards->add($board);
            $board->addCustomFieldDefinition($this);
        }
        return $this;
    }

    public function removeBoard(Board $board): static
    {
        if ($this->boards->removeElement($board)) {
            $board->removeCustomFieldDefinition($this);
        }
        return $this;
    }

    /**
     * Back-compat: fields are space-owned + board-attached now. Attaches the
     * board and, when the space isn't set yet, inherits it from the board —
     * so the many test seeders that still do `new CustomFieldDefinition()
     * ->setBoard($p)` keep producing valid (space + attachment) rows. Prefer
     * {@see setSpace()} + {@see addBoard()} in new code.
     */
    public function setBoard(?Board $board): static
    {
        if (null !== $board) {
            if (null === $this->space) {
                $this->space = $board->getSpace();
            }
            $this->addBoard($board);
        }
        return $this;
    }

    /** Back-compat: the first board this field is attached to, if any. */
    public function getBoard(): ?Board
    {
        $first = $this->boards->first();
        return false === $first ? null : $first;
    }

    /**
     * Keeps the legacy `required` column aligned with `nullable` on
     * every write so any downstream reader still inspecting the old
     * column sees the right value.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function syncRequiredFromNullable(): void
    {
        $this->required = !$this->nullable;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);
        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;
        return $this;
    }

    public function getSubtype(): string
    {
        return $this->subtype;
    }

    public function setSubtype(string $subtype): static
    {
        $this->subtype = $subtype;
        return $this;
    }

    /**
     * Composite `<kind>.<subtype>` lookup key — what the strategy registry
     * resolves against.
     */
    public function getTypeKey(): string
    {
        return $this->kind . '.' . $this->subtype;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): static
    {
        $this->config = $config;
        return $this;
    }

    /**
     * @return array{kind: string, label?: string}|null
     */
    public function getFooter(): ?array
    {
        return $this->footer;
    }

    /**
     * @param array{kind: string, label?: string}|null $footer
     */
    public function setFooter(?array $footer): static
    {
        $this->footer = $footer;
        return $this;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function setNullable(bool $nullable): static
    {
        $this->nullable = $nullable;
        $this->required = !$nullable;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function isRequired(): bool
    {
        return !$this->nullable;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;
        $this->nullable = !$required;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Translation helper for callers (mostly tests + the migration
     * backfill) that still speak the pre-#227 flat type names. Maps
     * the legacy label to the (kind, subtype) pair on the new model
     * and leaves a sane default config in place. Not part of the API
     * write surface — the canonical wire shape is `{kind, subtype,
     * config}`.
     */
    public function setType(string $type): static
    {
        [$kind, $subtype] = self::legacyTypeToKindSubtype($type);
        $this->kind = $kind;
        $this->subtype = $subtype;
        if (CustomFieldKind::SELECT->value === $kind) {
            $this->config['multi'] = $this->config['multi'] ?? false;
        } elseif (CustomFieldKind::BOOLEAN->value === $kind) {
            $this->config = [];
        } else {
            $this->config['multi'] = $this->config['multi'] ?? false;
        }
        return $this;
    }

    /**
     * Translation helper paired with {@see setType()}: takes the
     * pre-#227 flat `string[]` options shape and upgrades it to the
     * structured `config.options` form (`[{key, label}, ...]`) the
     * select strategies expect. Null/empty clears the options key.
     *
     * @param array<int, string>|null $options
     */
    public function setOptions(?array $options): static
    {
        if (null === $options || [] === $options) {
            unset($this->config['options']);
            return $this;
        }
        $structured = [];
        foreach ($options as $value) {
            $trimmed = trim($value);
            if ('' === $trimmed) {
                continue;
            }
            $structured[] = ['key' => $trimmed, 'label' => $trimmed];
        }
        $this->config['options'] = $structured;
        return $this;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function legacyTypeToKindSubtype(string $type): array
    {
        return match ($type) {
            self::TYPE_TEXT => [CustomFieldKind::TEXT->value, 'text'],
            self::TYPE_NUMBER => [CustomFieldKind::NUMERIC->value, 'float'],
            self::TYPE_DATE => [CustomFieldKind::DATE->value, 'date'],
            self::TYPE_DROPDOWN => [CustomFieldKind::SELECT->value, 'single'],
            self::TYPE_CHECKBOX => [CustomFieldKind::BOOLEAN->value, 'boolean'],
            default => [$type, $type],
        };
    }
}
