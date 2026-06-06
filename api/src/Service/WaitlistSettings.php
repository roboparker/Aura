<?php

namespace App\Service;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads and writes the single "is the instance in waitlist mode?" flag.
 *
 * The value is stored in the `app_setting` table so an admin can flip it at
 * runtime (an env var / parameter can't change without a redeploy). When no
 * row exists yet the answer falls back to `%app.waitlist_enabled_default%`,
 * which ships `true` in prod/staging and is relaxed to `false` in
 * dev/test (see config/services.yaml) — mirroring `app.password_min_strength`.
 */
final class WaitlistSettings
{
    public const SETTING_NAME = 'waitlist_enabled';

    public function __construct(
        private AppSettingRepository $settings,
        private EntityManagerInterface $em,
        #[Autowire('%app.waitlist_enabled_default%')]
        private bool $default,
    ) {
    }

    public function isEnabled(): bool
    {
        $row = $this->settings->findOneByName(self::SETTING_NAME);
        if (null === $row) {
            return $this->default;
        }

        return true === $row->getValue();
    }

    public function setEnabled(bool $enabled): void
    {
        $row = $this->settings->findOneByName(self::SETTING_NAME)
            ?? new AppSetting(self::SETTING_NAME);
        $row->setValue($enabled);
        $this->em->persist($row);
        $this->em->flush();
    }
}
