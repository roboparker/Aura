<?php

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Uid\Uuid;

/**
 * Tiny key/value store for instance-wide settings that an admin can change
 * at runtime (the env-var/parameter path can't be toggled without a redeploy).
 *
 * Deliberately NOT an API Platform resource — it's read and written only
 * through {@see \App\Service\WaitlistSettings} and the admin controller, the
 * same way per-user preferences are gated behind UserPreferencesController.
 * One row per `name`; `value` is JSON so future flags can store richer shapes.
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_setting')]
#[ORM\UniqueConstraint(name: 'uniq_app_setting_name', columns: ['name'])]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: 'json')]
    private mixed $value = null;

    /**
     * Maintained by Gedmo Timestampable on every UPDATE. Stored as `datetime`
     * (not immutable) so Gedmo can mutate it in place; seeded at construct
     * time so the first flush has a value for the NOT NULL column.
     */
    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    private \DateTimeInterface $updatedAt;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
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

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
}
