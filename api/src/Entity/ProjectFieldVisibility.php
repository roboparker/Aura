<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectFieldVisibilityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Per-project visibility override for a custom field (#custom-fields-project).
 *
 * Custom field definitions are shared across the projects that attach them,
 * but where a field is shown — the task list, the Kanban board, or both — is a
 * per-project choice. One row per (project, field) pair holds that choice; when
 * no row exists the definition's own `visibility` acts as the default.
 * Server-managed only (not an API resource): written through the per-project
 * visibility endpoints.
 *
 * The field is polymorphic like {@see CustomFieldValue}: exactly one of
 * {@see $definition} (space-owned) / {@see $globalDefinition} (instance-wide)
 * is set, so a project can override the visibility of either field source.
 */
#[ORM\Entity(repositoryClass: ProjectFieldVisibilityRepository::class)]
#[ORM\Table(name: 'project_field_visibility')]
#[ORM\UniqueConstraint(
    name: 'uniq_pfv_project_definition',
    columns: ['project_id', 'definition_id'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_pfv_project_global_definition',
    columns: ['project_id', 'global_definition_id'],
)]
#[ORM\Index(columns: ['definition_id'], name: 'idx_pfv_definition')]
#[ORM\Index(columns: ['global_definition_id'], name: 'idx_pfv_global_definition')]
class ProjectFieldVisibility
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: CustomFieldDefinition::class)]
    #[ORM\JoinColumn(name: 'definition_id', nullable: true, onDelete: 'CASCADE')]
    private ?CustomFieldDefinition $definition = null;

    #[ORM\ManyToOne(targetEntity: GlobalCustomFieldDefinition::class)]
    #[ORM\JoinColumn(name: 'global_definition_id', nullable: true, onDelete: 'CASCADE')]
    private ?GlobalCustomFieldDefinition $globalDefinition = null;

    // A comma-joined SET of surfaces (list/board/calendar); 32 chars holds all
    // three. Validated in the visibility controller, so no Assert\Choice here.
    #[ORM\Column(length: 32, options: ['default' => CustomFieldDefinition::VISIBILITY_BOTH])]
    private string $visibility = CustomFieldDefinition::VISIBILITY_BOTH;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getDefinition(): ?CustomFieldDefinition
    {
        return $this->definition;
    }

    public function setDefinition(CustomFieldDefinition $definition): static
    {
        $this->definition = $definition;

        return $this;
    }

    public function getGlobalDefinition(): ?GlobalCustomFieldDefinition
    {
        return $this->globalDefinition;
    }

    public function setGlobalDefinition(GlobalCustomFieldDefinition $globalDefinition): static
    {
        $this->globalDefinition = $globalDefinition;

        return $this;
    }

    /**
     * The field this override targets, whichever source it points at.
     */
    public function getEffectiveDefinition(): ?CustomFieldDefinitionInterface
    {
        return $this->definition ?? $this->globalDefinition;
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
}
