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
use App\Validator\ValidCustomFieldDefinition;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-project custom field definition. Widened in #227 from the flat
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
 * Read access mirrors the parent project's space (any space member).
 * Write access is space-admin only — the field schema is structural
 * and easy to disrupt for the rest of the project.
 */
#[ApiResource(
    shortName: 'CustomFieldDefinition',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getProject() !== null and object.getProject().isSpaceAdmin(user)))",
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getProject().isAccessibleBy(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getProject().isSpaceAdmin(user))",
            processor: CustomFieldDefinitionUpdateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getProject().isSpaceAdmin(user))",
        ),
    ],
    normalizationContext: ['groups' => ['custom_field_definition:read']],
    denormalizationContext: ['groups' => ['custom_field_definition:write']],
    order: ['position' => 'ASC', 'createdAt' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['position'], arguments: ['orderParameterName' => 'order'])]
#[ORM\Entity(repositoryClass: CustomFieldDefinitionRepository::class)]
#[ORM\Table(name: 'custom_field_definition')]
#[ORM\Index(columns: ['project_id', 'position'], name: 'idx_cfd_project_position')]
#[ORM\Index(columns: ['space_id'], name: 'idx_cfd_space')]
#[ORM\UniqueConstraint(name: 'uniq_cfd_project_name', columns: ['project_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\Loggable(logEntryClass: ActivityLog::class)]
#[UniqueEntity(
    fields: ['project', 'name'],
    message: 'A field with this name already exists in the project.',
)]
#[ValidCustomFieldDefinition]
class CustomFieldDefinition
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
    public const VISIBILITY_BOTH = 'both';

    /** @var list<string> */
    public const VISIBILITIES = [
        self::VISIBILITY_LIST,
        self::VISIBILITY_BOARD,
        self::VISIBILITY_BOTH,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['custom_field_definition:read'])]
    private ?Uuid $id = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Project is required.')]
    // Versioned so the audit log records which project the field belonged to —
    // the change-log endpoint keys on this to recover the history of fields
    // that have since been deleted (their log rows outlive the row itself).
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['custom_field_definition:read'])]
    private ?Space $space = null;

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
     * Where the field is shown to readers: in the project task list
     * (`list`), the Kanban board cards (`board`), or both (default).
     * The task detail drawer always shows every field regardless.
     */
    #[ORM\Column(length: 16, options: ['default' => self::VISIBILITY_BOTH])]
    #[Assert\Choice(
        choices: self::VISIBILITIES,
        message: 'Visibility must be one of: {{ choices }}.',
    )]
    #[Gedmo\Versioned]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
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
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;
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

    #[ORM\PrePersist]
    public function syncSpaceFromProject(): void
    {
        if (null === $this->space && null !== $this->project) {
            $this->space = $this->project->getSpace();
        }
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
