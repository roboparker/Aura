<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoardFieldVisibilityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Per-board visibility override for a custom field (#custom-fields-board).
 *
 * Custom field definitions are shared across the boards that attach them,
 * but where a field is shown — the task list, the Kanban board, or both — is a
 * per-board choice. One row per (board, field) pair holds that choice; when
 * no row exists the definition's own `visibility` acts as the default.
 * Server-managed only (not an API resource): written through the per-board
 * visibility endpoints.
 *
 * The field is polymorphic like {@see CustomFieldValue}: exactly one of
 * {@see $definition} (space-owned) / {@see $globalDefinition} (instance-wide)
 * is set, so a board can override the visibility of either field source.
 */
#[ORM\Entity(repositoryClass: BoardFieldVisibilityRepository::class)]
#[ORM\Table(name: 'board_field_visibility')]
#[ORM\UniqueConstraint(
    name: 'uniq_pfv_board_definition',
    columns: ['board_id', 'definition_id'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_pfv_board_global_definition',
    columns: ['board_id', 'global_definition_id'],
)]
#[ORM\Index(columns: ['definition_id'], name: 'idx_pfv_definition')]
#[ORM\Index(columns: ['global_definition_id'], name: 'idx_pfv_global_definition')]
class BoardFieldVisibility
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Board::class)]
    #[ORM\JoinColumn(name: 'board_id', nullable: false, onDelete: 'CASCADE')]
    private ?Board $board = null;

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

    public function getBoard(): ?Board
    {
        return $this->board;
    }

    public function setBoard(Board $board): static
    {
        $this->board = $board;

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
