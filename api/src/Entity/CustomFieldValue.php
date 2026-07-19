<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Repository\CustomFieldValueRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Per-task value for a {@see CustomFieldDefinition} (#84). Embedded as a
 * collection on {@see Task::$customFieldValues}; not directly exposed as
 * an API resource — clients add/remove/edit values exclusively through
 * `POST /tasks` and `PATCH /tasks/{id}`.
 *
 * The raw scalar lives in `value` as JSON (string|number|bool|null) so
 * we can round-trip every supported field type without column gymnastics.
 * Per-type shape and required-ness are policed by
 * {@see App\Validator\ValidCustomFieldValues} on the parent Task — keeping
 * the rules in one place means they see the task's board and can
 * reject definitions from other boards.
 */
#[ORM\Entity(repositoryClass: CustomFieldValueRepository::class)]
#[ORM\Table(name: 'custom_field_value')]
#[ORM\Index(columns: ['task_id'], name: 'idx_cfv_task')]
#[ORM\Index(columns: ['definition_id'], name: 'idx_cfv_definition')]
#[ORM\Index(columns: ['global_definition_id'], name: 'idx_cfv_global_definition')]
#[ORM\Index(columns: ['search_vector'], name: 'idx_cfv_search_vector', flags: ['gin'])]
#[ORM\UniqueConstraint(name: 'uniq_cfv_task_definition', columns: ['task_id', 'definition_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cfv_task_global_definition', columns: ['task_id', 'global_definition_id'])]
class CustomFieldValue
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['task:read'])]
    private ?Uuid $id = null;

    /**
     * Owning task. Set automatically by Task::addCustomFieldValue so
     * clients never have to (and never can) hand it in via the embed.
     */
    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'customFieldValues')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Task $task = null;

    /**
     * The space-owned definition this value is for, OR null when the value
     * points at a {@see $globalDefinition} instead. Exactly one of the two
     * is non-null — the XOR is enforced by {@see ValidCustomFieldValues} and
     * a DB CHECK (`chk_cfv_definition_exactly_one`). Reach the live
     * definition regardless of source through {@see getEffectiveDefinition()}.
     *
     * Bare IRI on read — embedding the full definition would balloon every
     * task payload by N fields. Clients already fetched the definitions when
     * rendering the board's field schema.
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: CustomFieldDefinition::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['task:read', 'task:write'])]
    private ?CustomFieldDefinition $definition = null;

    /**
     * The instance-wide global definition this value is for, OR null when
     * the value points at a space-owned {@see $definition}. The global
     * sibling in the polymorphic pair — see the note on {@see $definition}.
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: GlobalCustomFieldDefinition::class)]
    #[ORM\JoinColumn(name: 'global_definition_id', nullable: true, onDelete: 'CASCADE')]
    #[Groups(['task:read', 'task:write'])]
    private ?GlobalCustomFieldDefinition $globalDefinition = null;

    /**
     * Type-erased scalar (or list). Stored as JSON so a single column
     * can hold any kind/subtype the strategy registry supports — string
     * for text/url/dropdown, int|float for numeric, ISO date string for
     * date, bool for boolean, `{amount, currency}` for money,
     * `{user|task|page: "/iri/..."}` for references, and the
     * list-of-any-of-the-above shape when `config.multi=true`. Null is
     * a deliberate "no value" for a nullable field. Shape validation
     * lives in {@see App\Validator\ValidCustomFieldValues}.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['task:read', 'task:write'])]
    private mixed $value = null;

    /**
     * Plain-text projection of `value` for the FTS index. Written by the
     * type strategies on persist — references dereference the target's
     * display label, dates render as `Y-m-d`, money renders the amount
     * + currency, references emit the target's name so FTS hits work
     * against user-facing strings rather than UUIDs. Null/empty means
     * the value contributes nothing searchable.
     *
     * The Postgres-managed `search_vector` column is generated from this
     * (see Version20260513000000) so DQL can keep referencing
     * `cfv.searchVector` unchanged.
     */
    #[ORM\Column(name: 'value_search', type: 'text', nullable: true)]
    private ?string $valueSearch = null;

    /**
     * Postgres-managed tsvector projection of {@see $valueSearch},
     * populated by a STORED generated column — see Version20260513000000.
     * Mapped here so DQL can reference `cfv.searchVector` from the task
     * search filter's EXISTS subquery; never written from PHP, never
     * serialised.
     */
    #[ORM\Column(name: 'search_vector', type: 'text', nullable: true, insertable: false, updatable: false)]
    private ?string $searchVector = null;

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

    public function getDefinition(): ?CustomFieldDefinition
    {
        return $this->definition;
    }

    public function setDefinition(?CustomFieldDefinition $definition): static
    {
        $this->definition = $definition;
        return $this;
    }

    public function getGlobalDefinition(): ?GlobalCustomFieldDefinition
    {
        return $this->globalDefinition;
    }

    public function setGlobalDefinition(?GlobalCustomFieldDefinition $globalDefinition): static
    {
        $this->globalDefinition = $globalDefinition;
        return $this;
    }

    /**
     * The live definition this value belongs to, whichever source it points
     * at. Every custom-field consumer (validator, FTS subscriber, footer
     * aggregator, serializers) reads through this so it never has to care
     * whether the field is space-owned or global. Null only in the
     * transient invalid state where neither FK is set.
     */
    public function getEffectiveDefinition(): ?CustomFieldDefinitionInterface
    {
        return $this->definition ?? $this->globalDefinition;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function getValueSearch(): ?string
    {
        return $this->valueSearch;
    }

    public function setValueSearch(?string $valueSearch): static
    {
        $this->valueSearch = $valueSearch;
        return $this;
    }
}
