<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Repository\CustomFieldValueRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

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
 * the rules in one place means they see the task's project and can
 * reject definitions from other projects.
 */
#[ORM\Entity(repositoryClass: CustomFieldValueRepository::class)]
#[ORM\Table(name: 'custom_field_value')]
#[ORM\Index(columns: ['task_id'], name: 'idx_cfv_task')]
#[ORM\Index(columns: ['definition_id'], name: 'idx_cfv_definition')]
#[ORM\UniqueConstraint(name: 'uniq_cfv_task_definition', columns: ['task_id', 'definition_id'])]
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
     * Bare IRI on read — embedding the full definition would balloon
     * every task payload by N fields. Clients already fetched the
     * definitions when rendering the project's field schema.
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: CustomFieldDefinition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Custom field definition is required.')]
    #[Groups(['task:read', 'task:write'])]
    private ?CustomFieldDefinition $definition = null;

    /**
     * Type-erased scalar. Stored as JSON so a single column can hold any
     * of the five supported types; null is a deliberate "no value" for
     * an optional field. See {@see App\Validator\ValidCustomFieldValues}
     * for per-type shape rules.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['task:read', 'task:write'])]
    private mixed $value = null;

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

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;
        return $this;
    }
}
