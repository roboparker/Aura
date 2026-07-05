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
use App\Repository\TaskSectionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named group of tasks on a board board ("section"). Tasks reference a
 * section via {@see Task::$section}; a task with no section renders under the
 * implicit default "In progress" group, so existing tasks need no backfill.
 *
 * Access mirrors the parent board's space (any space member can read and
 * manage sections — they're lightweight board organisation, not structural
 * schema like custom fields). The denormalised `space` FK lets the shared
 * {@see App\Doctrine\AbstractSpaceAccessExtension} scope collections without
 * joining through `board`.
 */
#[ApiResource(
    shortName: 'TaskSection',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getBoard() !== null and object.getBoard().isAccessibleBy(user) and is_granted('space.boards.update', object)))",
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getBoard().isAccessibleBy(user) and is_granted('space.boards.read', object)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getBoard().isAccessibleBy(user) and is_granted('space.boards.update', object)))",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getBoard().isAccessibleBy(user) and is_granted('space.boards.update', object)))",
        ),
    ],
    normalizationContext: ['groups' => ['task_section:read']],
    denormalizationContext: ['groups' => ['task_section:write']],
    order: ['position' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['board' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['position'], arguments: ['orderParameterName' => 'order'])]
#[ORM\Entity(repositoryClass: TaskSectionRepository::class)]
#[ORM\Table(name: 'task_section')]
#[ORM\Index(columns: ['board_id', 'position'], name: 'idx_task_section_project_position')]
#[ORM\Index(columns: ['space_id'], name: 'idx_task_section_space')]
#[ORM\HasLifecycleCallbacks]
class TaskSection
{
    public const MAX_TITLE_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['task_section:read'])]
    private ?Uuid $id = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Board::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Board is required.')]
    #[Groups(['task_section:read', 'task_section:write'])]
    private ?Board $board = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['task_section:read'])]
    private ?Space $space = null;

    #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
    #[Assert\NotBlank(message: 'Section title is required.')]
    #[Assert\Length(
        max: self::MAX_TITLE_LENGTH,
        maxMessage: 'Section title cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['task_section:read', 'task_section:write'])]
    private string $title = '';

    #[ORM\Column(type: 'integer')]
    #[Groups(['task_section:read', 'task_section:write'])]
    private int $position = 0;

    /**
     * Denormalise the parent board's space on insert so the access extension
     * can scope by `space` without joining through `board` (mirrors
     * CustomFieldDefinition::syncSpaceFromProject).
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function syncSpaceFromProject(): void
    {
        if (null !== $this->board) {
            $this->space = $this->board->getSpace();
        }
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getBoard(): ?Board
    {
        return $this->board;
    }

    public function setBoard(?Board $board): self
    {
        $this->board = $board;

        return $this;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

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
}
