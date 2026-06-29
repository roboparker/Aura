<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Repository\TaskRelationshipRepository;
use App\Validator\ValidTaskRelationship;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A directed link between two tasks. One row stores the relationship from
 * `source` to `target`; the inverse label is derived per viewing side (see
 * {@see directionLabel()}). `related` is symmetric — the label is the same
 * both ways. Reads come through `GET /tasks/{id}/relationships`
 * ({@see App\Controller\TaskRelationshipController}); this resource only
 * exposes create/delete (+ item Get) since a relationship is reachable through
 * either of its tasks, both of which are already access-controlled.
 */
#[ApiResource(
    shortName: 'TaskRelationship',
    operations: [
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and object.getSource() !== null and object.getTarget() !== null and (is_granted('ROLE_ADMIN') or (object.getSource().isAccessibleBy(user) and object.getTarget().isAccessibleBy(user) and is_granted('space.tasks.update', object)))",
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or ((object.getSource().isAccessibleBy(user) or object.getTarget().isAccessibleBy(user)) and is_granted('space.tasks.read', object)))",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or ((object.getSource().isAccessibleBy(user) or object.getTarget().isAccessibleBy(user)) and is_granted('space.tasks.update', object)))",
        ),
    ],
    normalizationContext: ['groups' => ['task_relationship:read']],
    denormalizationContext: ['groups' => ['task_relationship:write']],
)]
#[ORM\Entity(repositoryClass: TaskRelationshipRepository::class)]
#[ORM\Table(name: 'task_relationship')]
#[ORM\Index(columns: ['source_id'], name: 'idx_task_relationship_source')]
#[ORM\Index(columns: ['target_id'], name: 'idx_task_relationship_target')]
#[ORM\UniqueConstraint(name: 'uniq_task_relationship', columns: ['source_id', 'target_id', 'type'])]
#[ValidTaskRelationship]
class TaskRelationship
{
    public const TYPE_PARENT = 'parent';
    public const TYPE_REQUIRED = 'required';
    public const TYPE_RELATED = 'related';
    public const TYPE_DUPLICATE = 'duplicate';

    public const TYPES = [
        self::TYPE_PARENT,
        self::TYPE_REQUIRED,
        self::TYPE_RELATED,
        self::TYPE_DUPLICATE,
    ];

    /** `related` reads the same in both directions. */
    public const SYMMETRIC_TYPES = [self::TYPE_RELATED];

    /** @var array<string, array{0: string, 1: string}> type => [outgoing, incoming] label */
    private const LABELS = [
        self::TYPE_PARENT => ['parent of', 'child of'],
        self::TYPE_REQUIRED => ['required for', 'required by'],
        self::TYPE_RELATED => ['related to', 'related to'],
        self::TYPE_DUPLICATE => ['duplicate of', 'duplicated by'],
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['task_relationship:read'])]
    private ?Uuid $id = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Source task is required.')]
    #[Groups(['task_relationship:read', 'task_relationship:write'])]
    private ?Task $source = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Target task is required.')]
    #[Groups(['task_relationship:read', 'task_relationship:write'])]
    private ?Task $target = null;

    #[ORM\Column(length: 16)]
    #[Assert\NotBlank(message: 'Relationship type is required.')]
    #[Assert\Choice(choices: self::TYPES, message: 'Invalid relationship type.')]
    #[Groups(['task_relationship:read', 'task_relationship:write'])]
    private string $type = self::TYPE_RELATED;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['task_relationship:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Human label for this relationship as seen from `$outgoing` side. */
    public static function labelFor(string $type, bool $outgoing): string
    {
        $labels = self::LABELS[$type] ?? ['related to', 'related to'];

        return $outgoing ? $labels[0] : $labels[1];
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSource(): ?Task
    {
        return $this->source;
    }

    public function setSource(?Task $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getTarget(): ?Task
    {
        return $this->target;
    }

    public function setTarget(?Task $target): self
    {
        $this->target = $target;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
