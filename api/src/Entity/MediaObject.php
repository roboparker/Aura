<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\State\MediaObjectUploadProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Shared media container. Owned by the uploading user, referenced by
 * domain entities (User.avatar today; tasks/projects/comments later).
 * `variants` stores a map of variant name to Flysystem path — images get
 * "thumb"/"profile" entries; plain attachments use "original".
 */
#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            uriTemplate: '/media-objects',
            processor: MediaObjectUploadProcessor::class,
            openapi: new Model\Operation(
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
            security: "is_granted('ROLE_USER')",
            deserialize: false,
        ),
    ],
    normalizationContext: ['groups' => ['media_object:read']],
)]
#[ORM\Entity]
#[ORM\Table(name: 'media_object')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_media_object_owner')]
class MediaObject
{
    public const KIND_AVATAR = 'avatar';
    public const KIND_ATTACHMENT = 'attachment';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['media_object:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 32)]
    #[Groups(['media_object:read'])]
    private string $kind = self::KIND_ATTACHMENT;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json')]
    private array $variants = [];

    #[ORM\Column(length: 255)]
    #[Groups(['media_object:read'])]
    private string $originalName = '';

    #[ORM\Column(length: 100)]
    #[Groups(['media_object:read'])]
    private string $mimeType = '';

    #[ORM\Column(type: 'integer')]
    #[Groups(['media_object:read'])]
    private int $byteSize = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['media_object:read'])]
    private \DateTimeImmutable $createdOn;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
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

    /** @return array<string, string> */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /** @param array<string, string> $variants */
    public function setVariants(array $variants): static
    {
        $this->variants = $variants;
        return $this;
    }

    public function getVariantPath(string $variant): ?string
    {
        return $this->variants[$variant] ?? null;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;
        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function setByteSize(int $byteSize): static
    {
        $this->byteSize = $byteSize;
        return $this;
    }

    public function getCreatedOn(): \DateTimeImmutable
    {
        return $this->createdOn;
    }

    /**
     * Variants serialized as public URLs, keyed by variant name.
     *
     * @return array<string, string>
     */
    #[Groups(['media_object:read'])]
    public function getVariantUrls(): array
    {
        $urls = [];
        foreach ($this->variants as $name => $path) {
            $urls[$name] = '/media/' . ltrim($path, '/');
        }
        return $urls;
    }
}
