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
use App\Repository\CustomFieldDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Per-project custom field definition (#84). Each project can declare
 * its own set of fields that the eventual task UI will render
 * dynamically; values themselves (CustomFieldValue) land in a follow-up
 * — this PR ships only the definitions + CRUD so the project-settings
 * UI can be built against a stable surface first.
 *
 * Read access mirrors the parent project (members + admins). Write
 * access is owner-only — the field schema is structural and easy to
 * disrupt for the rest of the project, so we keep mutation narrow
 * even though every member can post tasks.
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

    #[ORM\Column(length: 16)]
    #[Assert\Choice(
        choices: self::ALLOWED_TYPES,
        message: 'Type must be one of: text, number, date, dropdown, checkbox.',
    )]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private string $type = self::TYPE_TEXT;

    /**
     * Required only when type is `dropdown`. Stored as a JSON array of
     * non-empty unique strings — the choices the picker presents.
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private ?array $options = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Position cannot be negative.')]
    #[Groups(['custom_field_definition:read', 'custom_field_definition:write'])]
    private int $position = 0;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return array<int, string>|null
     */
    public function getOptions(): ?array
    {
        return $this->options;
    }

    /**
     * @param array<int, string>|null $options
     */
    public function setOptions(?array $options): static
    {
        $this->options = $options;
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
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Cross-field validation: dropdown fields must declare at least one
     * non-empty option; non-dropdown fields must not declare any. The
     * Choice constraint above already handles the type allow-list, so
     * we only police the `options` shape here.
     */
    #[Assert\Callback]
    public function validateOptions(ExecutionContextInterface $context): void
    {
        if (self::TYPE_DROPDOWN === $this->type) {
            $cleaned = array_values(array_filter(
                array_map(static fn ($v) => is_string($v) ? trim($v) : '', $this->options ?? []),
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
            return;
        }

        if (null !== $this->options && [] !== $this->options) {
            $context->buildViolation('Only dropdown fields can declare options.')
                ->atPath('options')
                ->addViolation();
        }
    }
}
