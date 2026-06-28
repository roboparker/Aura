<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\SpaceRoleRepository;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A space admin-defined role (#space-roles): a named, per-space permission set
 * (content category × CRUD action) that admins assign to members to RESTRICT
 * what they can do. A member's effective permissions are the union of their
 * assigned roles; a member with no roles is unrestricted (back-compat), and
 * space admins are never restricted.
 *
 * Reads are scoped to the caller's spaces by {@see
 * \App\Doctrine\SpaceRoleAccessExtension}; writes require space admin.
 */
#[ApiResource(
    shortName: 'SpaceRole',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and object.getSpace() !== null and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user))",
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().hasMember(user))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user))",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user))",
        ),
    ],
    normalizationContext: ['groups' => ['space_role:read']],
    denormalizationContext: ['groups' => ['space_role:write']],
    order: ['name' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact'])]
#[ORM\Entity(repositoryClass: SpaceRoleRepository::class)]
#[ORM\Table(name: 'space_role')]
#[ORM\Index(columns: ['space_id'], name: 'idx_space_role_space')]
#[ORM\UniqueConstraint(name: 'uniq_space_role_name', columns: ['space_id', 'name'])]
class SpaceRole
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['space_role:read', 'space:read'])]
    private ?Uuid $id = null;

    /**
     * The space this role belongs to. Set on create; immutable after (not in
     * the write group beyond Post).
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['space_role:read', 'space_role:write'])]
    private ?Space $space = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Role name is required.')]
    #[Assert\Length(max: 80, maxMessage: 'Role name cannot be longer than {{ limit }} characters.')]
    #[Groups(['space_role:read', 'space_role:write', 'space:read'])]
    private string $name = '';

    /**
     * Hex color for the role badge, or null to fall back to a default in the
     * UI.
     */
    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(
        pattern: '/^#[0-9a-fA-F]{6}$/',
        message: 'Color must be a hex value like #22c55e.',
    )]
    #[Groups(['space_role:read', 'space_role:write', 'space:read'])]
    private ?string $color = null;

    /**
     * Permission matrix `{category: {action: bool}}` over
     * {@see SpacePermission}. A missing category/action reads as denied.
     * Typed loosely because it's denormalized from untrusted JSON and
     * validated by {@see validatePermissions()}; read it through
     * {@see allows()}.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    #[Groups(['space_role:read', 'space_role:write'])]
    private array $permissions = [];

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['space_role:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = null === $color ? null : strtolower($color);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * @param array<string, mixed> $permissions
     */
    public function setPermissions(array $permissions): static
    {
        $this->permissions = $permissions;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Whether this role grants (category, action). Defensive against malformed JSON. */
    public function allows(string $category, string $action): bool
    {
        $actions = $this->permissions[$category] ?? null;

        return is_array($actions) && true === ($actions[$action] ?? false);
    }

    #[Assert\Callback]
    public function validatePermissions(ExecutionContextInterface $context): void
    {
        foreach ($this->permissions as $category => $actions) {
            if (!SpacePermission::isCategory($category)) {
                $context->buildViolation('Unknown permission category: {{ value }}.')
                    ->setParameter('{{ value }}', $category)
                    ->atPath('permissions')
                    ->addViolation();
                continue;
            }
            if (!is_array($actions)) {
                $context->buildViolation('Permissions for {{ value }} must be a map of action => bool.')
                    ->setParameter('{{ value }}', $category)
                    ->atPath('permissions')
                    ->addViolation();
                continue;
            }
            foreach ($actions as $action => $granted) {
                if (!is_string($action) || !SpacePermission::isAction($action)) {
                    $context->buildViolation('Unknown permission action: {{ value }}.')
                        ->setParameter('{{ value }}', is_string($action) ? $action : gettype($action))
                        ->atPath('permissions')
                        ->addViolation();
                    continue;
                }
                if (!is_bool($granted)) {
                    $context->buildViolation('Permission {{ value }} must be true or false.')
                        ->setParameter('{{ value }}', $category . '.' . $action)
                        ->atPath('permissions')
                        ->addViolation();
                }
            }
        }
    }
}
