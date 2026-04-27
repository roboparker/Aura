<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Service\AvatarColorService;
use App\State\UserPasswordHasherProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Post(processor: UserPasswordHasherProcessor::class),
        new Get(security: "is_granted('ROLE_ADMIN') or object == user"),
        new Patch(security: "is_granted('ROLE_ADMIN') or object == user"),
    ],
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:write']],
)]
#[ORM\Entity]
#[ORM\Table(name: '`user`')]
#[ORM\Index(columns: ['avatar_id'], name: 'idx_user_avatar')]
#[UniqueEntity('email', message: 'This email is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['user:read', 'project:read', 'group:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read'])]
    private string $email = '';

    #[ORM\Column]
    private string $password = '';

    #[Assert\NotBlank(groups: ['user:write'])]
    #[Assert\Length(min: 6, minMessage: 'Password must be at least {{ limit }} characters.')]
    #[Groups(['user:write'])]
    private ?string $plainPassword = null;

    /**
     * Transient token from a UserInvite. When the signup payload includes
     * one, UserPasswordHasherProcessor resolves it after persisting the new
     * user and joins them to the invited group. Never stored.
     */
    #[Groups(['user:write'])]
    private ?string $inviteToken = null;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    #[Groups(['user:read'])]
    private array $roles = [];

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Given name is required.')]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read'])]
    private string $givenName = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Family name is required.')]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read'])]
    private string $familyName = '';

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read'])]
    private ?string $nickname = null;

    /**
     * Hex color (e.g., "#1e6091") used behind the initials fallback when the
     * user has no avatar. Initialized to a random palette entry at
     * registration; the user can swap it for any other palette entry on
     * /account, but never to a free-form value (to keep contrast safe).
     */
    #[ORM\Column(length: 7)]
    #[Assert\Choice(
        choices: AvatarColorService::PALETTE,
        message: 'Pick a color from the available palette.',
    )]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read'])]
    private string $personalizedColor = AvatarColorService::PALETTE[0];

    #[ORM\ManyToOne(targetEntity: MediaObject::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['user:write'])]
    private ?MediaObject $avatar = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function getInviteToken(): ?string
    {
        return $this->inviteToken;
    }

    public function setInviteToken(?string $inviteToken): static
    {
        $this->inviteToken = $inviteToken;
        return $this;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /** @param string[] $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getGivenName(): string
    {
        return $this->givenName;
    }

    public function setGivenName(string $givenName): static
    {
        $this->givenName = $givenName;
        return $this;
    }

    public function getFamilyName(): string
    {
        return $this->familyName;
    }

    public function setFamilyName(string $familyName): static
    {
        $this->familyName = $familyName;
        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): static
    {
        $this->nickname = $nickname;
        return $this;
    }

    public function getPersonalizedColor(): string
    {
        return $this->personalizedColor;
    }

    public function setPersonalizedColor(string $personalizedColor): static
    {
        $this->personalizedColor = $personalizedColor;
        return $this;
    }

    public function getAvatar(): ?MediaObject
    {
        return $this->avatar;
    }

    public function setAvatar(?MediaObject $avatar): static
    {
        $this->avatar = $avatar;
        return $this;
    }

    /**
     * Avatar variant URLs keyed by size ("thumb", "profile"). Null when the
     * user has no avatar — the frontend falls back to initials on
     * personalizedColor.
     *
     * @return array<string, string>|null
     */
    #[Groups(['user:read', 'project:read', 'group:read'])]
    public function getAvatarUrls(): ?array
    {
        return $this->avatar?->getVariantUrls();
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
        $this->inviteToken = null;
    }
}
