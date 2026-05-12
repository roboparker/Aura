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
use App\CustomField\Footer\FooterKind;
use App\Repository\CustomFieldDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Per-project custom field definition (#84). Each project can declare
 * its own set of fields tasks render dynamically. PR #227 widened the
 * shape from a flat 5-type enum to a (kind, subtype, config) triple
 * backed by a strategy registry so adding new kinds is one class drop.
 *
 * Storage shape (after migration Version20260513000000):
 *   - `kind`     — top-level family (boolean|text|numeric|date|select|reference)
 *   - `subtype`  — specialisation within the kind (e.g. `money` under `numeric`)
 *   - `config`   — per-kind config payload (options, min/max, currency, multi, …)
 *   - `footer`   — optional `{kind, label?}` aggregation descriptor
 *   - `nullable` — whether a missing value is legal for this field
 *
 * Read access mirrors the parent project's space (any space member).
 * Write access is space-admin only — the field schema is structural
 * and easy to disrupt, so we keep mutation narrow even though every
 * member can post tasks.
 *
 * Legacy `getType()` / `getOptions()` / `setType()` / `setOptions()`
 * accessors are kept as derived transition shims (NOT mapped to DB
 * columns) so the existing validator, MCP serializer, ProjectCopy
 * controller, and tests continue working between this commit and the
 * later validator-rewrite commit. They MUST be removed once the
 * downstream call sites have been ported.
 */
#[ApiResource(
    shortName: 'CustomFieldDefinition',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            // Write access is space-admin only (#185). Read access for
            // anyone in the space; the schema is structural and easy to
            // disrupt for the rest of the project, so we keep mutation
            // narrow even though every member can post tasks.
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getProject() !== null and object.getProject().isSpaceAdmin(user)))",
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getProject().isAccessibleBy(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getProject().isSpaceAdmin(user))",
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
#[UniqueEntity(
    fields: ['project', 'name'],
    message: 'A field with this name already exists in the project.',
)]
class CustomFieldDefinition
{
    /**
     * Legacy flat-type constants kept for the transition window so the
     * pre-strategy validator + tests still compile. Each maps to a
     * (kind, subtype) pair via {@see legacyTypeToKindSubtype()}.
     */
    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_DROPDOWN = 'dropdown';
    public const TYPE_CHECKBOX = 'checkbox';

    public const ALLOWED_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_DROPDOWN,
        self::TYPE_CHECKBOX,
    ];

    public const MAX_NAME_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['custom_field_definition:read'])]
    private ?Uuid $id = null;

    /**
     * Bare IRI on the read side — same shape as TaskComment.parentComment.
     * Embedding the whole project would balloon the list payload for a
     * project with many members.
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Project is required.')]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private ?Project $project = null;

    /**
     * The space this definition lives in (#181). Inherited from the
     * parent project on PrePersist; never settable on the wire. Kept
     * as a denormalised column so PR 4's space-scoped catalog views
     * can filter without joining through `project`.
     */
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
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private string $name = '';

    /**
     * Top-level family — one of {@see CustomFieldKind}'s values. Identifies
     * which strategy family owns this field's editor + validation.
     */
    #[ORM\Column(length: 16)]
    #[Assert\Choice(
        callback: [CustomFieldKind::class, 'values'],
        message: 'Kind must be one of: {{ choices }}.',
    )]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private string $kind = CustomFieldKind::TEXT->value;

    /**
     * Specialisation within the kind (e.g. `money` under `numeric`,
     * `single` vs `multi` under `select`). The (kind, subtype) pair is
     * the registry lookup key for the concrete strategy.
     */
    #[ORM\Column(length: 24)]
    #[Assert\NotBlank(message: 'Subtype is required.')]
    #[Assert\Length(max: 24)]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private string $subtype = 'text';

    /**
     * Per-kind configuration payload. Shape is owned by the strategy:
     *   - numeric: `{min?, max?, decimalPlaces?, currency?, multi}`
     *   - text:    `{minLength?, maxLength?, pattern?, multi}`
     *   - select:  `{options: [{key, label, color?}], multi}`
     *   - date:    `{min?, max?, multi}`
     *   - reference: `{multi}`
     *   - boolean: `{}`
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private array $config = [];

    /**
     * Optional footer aggregation descriptor `{kind, label?}`. Null means
     * no footer row shown for this field; non-null kind must be in
     * {@see FooterKind} and supported by the strategy.
     *
     * @var array{kind: string, label?: string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private ?array $footer = null;

    /**
     * Whether a missing or null value is acceptable for this field. The
     * inverse of the legacy `required` flag — kept under the new name
     * for clearer semantics ("can this value be null?"). The legacy
     * `required` column is preserved for backwards-compat and kept in
     * sync via {@see syncRequiredFromNullable()}; the validator-rewrite
     * commit will collapse the two.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private bool $nullable = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Position cannot be negative.')]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private int $position = 0;

    /**
     * Legacy required flag. Mirrors `!nullable`; both columns are kept
     * during the transition window so older code paths that read
     * `required` (validator, MCP serializer) keep working. The
     * validator-rewrite commit will drop this in favour of `nullable`.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
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

    /**
     * Mirrors the parent project's space onto this definition before
     * insert. Same pattern as Discussion::syncSpaceFromProject.
     */
    #[ORM\PrePersist]
    public function syncSpaceFromProject(): void
    {
        if (null === $this->space && null !== $this->project) {
            $this->space = $this->project->getSpace();
        }
    }

    /**
     * Keeps the legacy `required` column aligned with `nullable` on
     * every write. Only the new column is the source of truth from
     * the strategies' perspective, but downstream readers that haven't
     * migrated yet still inspect `required`.
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

    public function isRequired(): bool
    {
        return $this->required;
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
     * Transition shim: derives the legacy flat type from the new
     * (kind, subtype) pair. Removed once the validator + MCP serializer
     * + ProjectCopy controller migrate to the strategy registry.
     */
    #[Groups(['custom_field_definition:read'])]
    public function getType(): string
    {
        return match (true) {
            CustomFieldKind::TEXT->value === $this->kind => self::TYPE_TEXT,
            CustomFieldKind::NUMERIC->value === $this->kind => self::TYPE_NUMBER,
            CustomFieldKind::DATE->value === $this->kind => self::TYPE_DATE,
            CustomFieldKind::SELECT->value === $this->kind => self::TYPE_DROPDOWN,
            CustomFieldKind::BOOLEAN->value === $this->kind => self::TYPE_CHECKBOX,
            // Reference is new in #227 — no legacy mapping. Return the
            // kind verbatim so legacy readers fail loud rather than
            // silently mis-classify a reference as text.
            default => $this->kind,
        };
    }

    /**
     * Transition shim: accepts the legacy flat type and maps it to
     * (kind, subtype) on the new columns. Preserves the test + API
     * payload shape `{type: 'dropdown', options: [...]}` until the
     * validator rewrite + PWA rewrite cuts everything over.
     */
    #[Groups(['custom_field_definition:write'])]
    public function setType(string $type): static
    {
        [$kind, $subtype] = self::legacyTypeToKindSubtype($type);
        $this->kind = $kind;
        $this->subtype = $subtype;

        // Carry forward the multi flag where the strategy will need it.
        // Select is the only kind whose multi-ness is baked into the
        // subtype (single vs multi); for everything else we default to
        // single and let `config.multi` toggle later.
        if (CustomFieldKind::SELECT->value !== $kind) {
            $this->config['multi'] = $this->config['multi'] ?? false;
        }
        return $this;
    }

    /**
     * Transition shim: returns the legacy flat-string options list for
     * dropdown fields, derived from the new structured `config.options`
     * objects. Non-dropdowns get null to match the old contract.
     *
     * @return array<int, string>|null
     */
    #[Groups(['custom_field_definition:read'])]
    public function getOptions(): ?array
    {
        if (CustomFieldKind::SELECT->value !== $this->kind) {
            return null;
        }
        $options = $this->config['options'] ?? null;
        if (!is_array($options) || [] === $options) {
            return null;
        }
        $labels = [];
        foreach ($options as $entry) {
            if (is_array($entry) && isset($entry['label']) && is_string($entry['label'])) {
                $labels[] = $entry['label'];
            } elseif (is_string($entry)) {
                $labels[] = $entry;
            }
        }
        return [] === $labels ? null : $labels;
    }

    /**
     * Transition shim: accepts the legacy flat options array and
     * upgrades it to the structured `config.options` shape
     * `[{key, label}, ...]`. Null/empty clears the options key.
     *
     * @param array<int, string>|null $options
     */
    #[Groups(['custom_field_definition:write'])]
    public function setOptions(?array $options): static
    {
        if (null === $options || [] === $options) {
            unset($this->config['options']);
            return $this;
        }
        $structured = [];
        foreach ($options as $value) {
            if (!is_string($value)) {
                continue;
            }
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
     * Maps a legacy flat type string to its `(kind, subtype)` equivalent.
     * Unknown inputs return `(kind, kind)` so a typo'd `setType('foo')`
     * still produces a deterministic shape the validator can reject.
     *
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

    /**
     * Cross-field validation: dropdown fields must declare at least one
     * non-empty option; non-dropdown fields must not declare any. The
     * Choice constraint above already handles the type allow-list, so
     * we only police the `options` shape here.
     *
     * Operates on the legacy projection so the existing CFD test suite
     * (which still POSTs `{type, options}` payloads) keeps the same
     * behaviour through this commit. The validator-rewrite commit
     * replaces this with a strategy-dispatched `validateConfig()` pass.
     */
    #[Assert\Callback]
    public function validateOptions(ExecutionContextInterface $context): void
    {
        // Inspect `config.options` directly rather than going through the
        // legacy `getOptions()` projection — the projection short-circuits
        // to null for non-dropdown kinds, which would hide the "options
        // posted on a text field" case the legacy contract rejects.
        $rawOptions = $this->config['options'] ?? null;
        $hasOptions = is_array($rawOptions) && [] !== $rawOptions;

        if (self::TYPE_DROPDOWN !== $this->getType()) {
            if ($hasOptions) {
                $context->buildViolation('Only dropdown fields can declare options.')
                    ->atPath('options')
                    ->addViolation();
            }
            return;
        }

        $cleaned = array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $this->getOptions() ?? []),
            static fn (string $v) => '' !== $v,
        ));
        if (count($cleaned) < 1) {
            $context->buildViolation('Dropdown fields require at least one option.')
                ->atPath('options')
                ->addViolation();
            return;
        }
        if (count($cleaned) !== count(array_unique($cleaned))) {
            $context->buildViolation('Dropdown options must be unique.')
                ->atPath('options')
                ->addViolation();
        }
    }

    /**
     * Footer descriptor sanity check: when present, `kind` must be a
     * known {@see FooterKind} value. Per-strategy "is this aggregation
     * supported for this kind?" gating lands with the footer endpoint
     * commit.
     */
    #[Assert\Callback]
    public function validateFooter(ExecutionContextInterface $context): void
    {
        if (null === $this->footer) {
            return;
        }
        $kind = $this->footer['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, FooterKind::values(), true)) {
            $context->buildViolation('Footer kind must be one of: {{ choices }}.')
                ->setParameter('{{ choices }}', implode(', ', FooterKind::values()))
                ->atPath('footer.kind')
                ->addViolation();
        }
    }
}
