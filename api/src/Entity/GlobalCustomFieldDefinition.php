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
use App\Repository\GlobalCustomFieldDefinitionRepository;
use App\State\GlobalCustomFieldVisibilityProvider;
use App\Validator\ValidCustomFieldDefinition;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Instance-wide, admin-managed custom field definition. The sibling of the
 * space-owned {@see CustomFieldDefinition}: it carries the exact same
 * (kind, subtype, config, footer, nullable) shape and resolves to the same
 * {@see App\CustomField\Type\CustomFieldTypeInterface} strategy registry, so
 * validation, value editors, footers, and search behave identically — the
 * only difference is scope. A global field lives in its own table, belongs
 * to no space, and is available to every board in every space.
 *
 * Only platform admins (`ROLE_ADMIN`) create / edit / delete definitions;
 * any authenticated user may read them (so they render in the board field
 * picker) and toggle them on/off per board via
 * {@see Board::$globalCustomFieldDefinitions}. A board's effective field
 * set is the union of its space fields + its opted-in global fields.
 *
 * The `reference` kind is disallowed for global fields in v1 — a reference
 * value scopes its target IRIs against the field's space, and a global field
 * has none. This is enforced in {@see App\Validator\ValidCustomFieldDefinition}.
 */
#[ApiResource(
    shortName: 'GlobalCustomFieldDefinition',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            provider: GlobalCustomFieldVisibilityProvider::class,
        ),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Get(
            security: "is_granted('ROLE_USER')",
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Delete(
            // System fields (e.g. the Timeline start field) back a feature and
            // must not be deletable, or the feature breaks instance-wide.
            security: "is_granted('ROLE_ADMIN') and object.getSystemKey() === null",
            securityMessage: 'This is a system field and cannot be deleted.',
        ),
    ],
    normalizationContext: ['groups' => ['global_custom_field_definition:read']],
    denormalizationContext: ['groups' => ['global_custom_field_definition:write']],
    order: ['position' => 'ASC', 'createdAt' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['boards' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['position'], arguments: ['orderParameterName' => 'order'])]
#[ORM\Entity(repositoryClass: GlobalCustomFieldDefinitionRepository::class)]
#[ORM\Table(name: 'global_custom_field_definition')]
#[ORM\UniqueConstraint(name: 'uniq_gcfd_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(
    fields: ['name'],
    message: 'A global field with this name already exists.',
)]
#[ValidCustomFieldDefinition]
class GlobalCustomFieldDefinition implements CustomFieldDefinitionInterface
{
    public const MAX_NAME_LENGTH = 80;

    /** systemKey of the canonical global field driving the board Timeline (#timeline). */
    public const SYSTEM_TIMELINE_START = 'timeline_start';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['global_custom_field_definition:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'Field name is required.')]
    #[Assert\Length(
        max: self::MAX_NAME_LENGTH,
        maxMessage: 'Field name cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['global_custom_field_definition:read', 'global_custom_field_definition:write'])]
    private string $name = '';

    /**
     * Top-level family — one of {@see CustomFieldKind}'s values.
     */
    #[ORM\Column(length: 16)]
    #[Assert\Choice(
        callback: [CustomFieldKind::class, 'values'],
        message: 'Kind must be one of: {{ choices }}.',
    )]
    #[Groups(['global_custom_field_definition:read', 'global_custom_field_definition:write'])]
    private string $kind = CustomFieldKind::TEXT->value;

    /**
     * Specialisation within the kind. The (kind, subtype) pair is the
     * registry lookup key for the concrete strategy.
     */
    #[ORM\Column(length: 24)]
    #[Assert\NotBlank(message: 'Subtype is required.')]
    #[Assert\Length(max: 24)]
    #[Groups(['global_custom_field_definition:read', 'global_custom_field_definition:write'])]
    private string $subtype = 'text';

    /**
     * Per-kind configuration payload. Shape is owned by the strategy for
     * the (kind, subtype) pair — see CustomFieldTypeInterface docblock.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    #[Groups(['global_custom_field_definition:read', 'global_custom_field_definition:write'])]
    private array $config = [];

    /**
     * Optional footer aggregation descriptor `{kind, label?}`. Null means
     * no footer row; the kind, when present, must be one supported by the
     * strategy (enforced in {@see ValidCustomFieldDefinition}).
     *
     * @var array{kind: string, label?: string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['global_custom_field_definition:read', 'global_custom_field_definition:write'])]
    private ?array $footer = null;

    /**
     * Whether a missing or null value is acceptable for this field.
     * `false` means the task validator enforces presence at save time.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['global_custom_field_definition:read', 'global_custom_field_definition:write'])]
    private bool $nullable = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Position cannot be negative.')]
    #[Groups(['global_custom_field_definition:read', 'global_custom_field_definition:write'])]
    private int $position = 0;

    /**
     * Default surface visibility — where the field shows: the task list
     * (`list`), the Kanban board (`board`), or both. Like the space-owned
     * field, this is the per-board fallback: a {@see BoardFieldVisibility}
     * override row wins in a board context (#custom-fields-board). Unlike
     * the space field, admins set this default directly (it is writable) since
     * there is no per-space manager to own it.
     */
    #[ORM\Column(length: 16, options: ['default' => CustomFieldDefinition::VISIBILITY_BOTH])]
    #[Assert\Choice(
        choices: CustomFieldDefinition::VISIBILITIES,
        message: 'Visibility must be one of: {{ choices }}.',
    )]
    #[Groups(['global_custom_field_definition:read', 'global_custom_field_definition:write'])]
    private string $visibility = CustomFieldDefinition::VISIBILITY_BOTH;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['global_custom_field_definition:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Stable identifier for a **system** field the app itself provisions and
     * depends on — null for ordinary admin-created fields. A non-null key marks
     * the field undeletable (a feature relies on it) and lets code resolve it by
     * a fixed handle instead of a name that could be renamed or localized.
     *
     * The Timeline feature's canonical "Start date" field carries
     * {@see self::SYSTEM_TIMELINE_START}; see the seed in the migration.
     */
    #[ORM\Column(length: 32, nullable: true, unique: true)]
    #[Groups(['global_custom_field_definition:read'])]
    private ?string $systemKey = null;

    /**
     * Boards that have opted this global field into their task view.
     * Inverse side — the join table is owned by
     * {@see Board::$globalCustomFieldDefinitions}. Backs the `?boards=`
     * filter the PWA uses to fetch a board's effective global field set.
     *
     * @var Collection<int, Board>
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToMany(targetEntity: Board::class, mappedBy: 'globalCustomFieldDefinitions')]
    private Collection $boards;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->boards = new ArrayCollection();
    }

    /**
     * @return Collection<int, Board>
     */
    public function getBoards(): Collection
    {
        return $this->boards;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSystemKey(): ?string
    {
        return $this->systemKey;
    }

    /**
     * The Timeline start field has to stay a date field or the feature it backs
     * breaks. `systemKey` is read-only (off the write group), so only the kind
     * could be edited into something incompatible — reject that.
     */
    #[Assert\Callback]
    public function validateSystemField(ExecutionContextInterface $context): void
    {
        if (
            self::SYSTEM_TIMELINE_START === $this->systemKey
            && CustomFieldKind::DATE->value !== $this->kind
        ) {
            $context->buildViolation('The Start date field must remain a date field.')
                ->atPath('kind')
                ->addViolation();
        }
    }

    public function setSystemKey(?string $systemKey): static
    {
        $this->systemKey = $systemKey;
        return $this;
    }

    /** A system field the app provisions and depends on — undeletable. */
    public function isSystem(): bool
    {
        return null !== $this->systemKey;
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

    /**
     * Global fields belong to no space. Part of the shared
     * {@see CustomFieldDefinitionInterface} contract — the reference
     * strategy reads it, which is why global fields disallow references.
     */
    public function getSpace(): ?Space
    {
        return null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
