<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\AvatarColorService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Snap `tag.color` onto the WCAG-AA avatar palette.
 *
 * `Tag.color` previously accepted any `#RRGGBB` value, and tag chips render
 * their label over that colour — seeded tags reached only 2.15:1 against the
 * white text the chip hardcoded. The entity now constrains the column with
 * `Assert\Choice(AvatarColorService::PALETTE)`, matching Space and UserGroup.
 *
 * Without this backfill that constraint would be a latent break rather than a
 * fix: API Platform validates the *whole* entity on PATCH, so a pre-existing
 * out-of-palette tag would 422 on an unrelated title edit.
 *
 * Colours are matched by **hue**, not RGB distance, so a tag keeps its colour
 * family: teal-600 becomes teal-700 rather than the numerically-nearer
 * cyan-700. Desaturated values (any grey, black, white) have no meaningful hue
 * and collapse to the palette's neutral slate.
 *
 * Irreversible by nature — the original arbitrary colours aren't recoverable
 * once mapped, and `down()` would have nothing to restore them from.
 */
final class Version20260727120000 extends AbstractMigration
{
    /** Below this saturation a colour has no useful hue; use the neutral. */
    private const NEUTRAL_SATURATION = 0.15;
    private const NEUTRAL = '#334155'; // slate-700

    public function getDescription(): string
    {
        return 'Snap tag.color onto the WCAG-AA avatar palette (hue-matched).';
    }

    public function up(Schema $schema): void
    {
        $colors = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT color FROM tag',
        );

        foreach ($colors as $color) {
            if (!is_string($color) || in_array($color, AvatarColorService::PALETTE, true)) {
                continue;
            }

            $this->addSql(
                'UPDATE tag SET color = :new WHERE color = :old',
                ['new' => $this->snapToPalette($color), 'old' => $color],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Original tag colours are not recoverable once snapped to the palette.',
        );
    }

    /** Nearest palette entry by circular hue distance, with a neutral fallback. */
    private function snapToPalette(string $color): string
    {
        [$hue, $saturation] = $this->toHueSaturation($color);

        if (null === $hue || $saturation < self::NEUTRAL_SATURATION) {
            return self::NEUTRAL;
        }

        $best = self::NEUTRAL;
        $bestDistance = PHP_FLOAT_MAX;

        foreach (AvatarColorService::PALETTE as $candidate) {
            if (self::NEUTRAL === $candidate) {
                continue; // reserved for the desaturated case above
            }

            [$candidateHue] = $this->toHueSaturation($candidate);
            if (null === $candidateHue) {
                continue;
            }

            $distance = abs($hue - $candidateHue);
            $distance = min($distance, 360.0 - $distance);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @return array{0: float|null, 1: float} hue in degrees (null when grey), saturation 0..1
     */
    private function toHueSaturation(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (6 !== strlen($hex) || 1 !== preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [null, 0.0];
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        $lightness = ($max + $min) / 2;

        if (0.0 === $delta) {
            return [null, 0.0];
        }

        $saturation = $lightness > 0.5
            ? $delta / (2 - $max - $min)
            : $delta / ($max + $min);

        if ($max === $r) {
            $hue = fmod(($g - $b) / $delta, 6);
        } elseif ($max === $g) {
            $hue = ($b - $r) / $delta + 2;
        } else {
            $hue = ($r - $g) / $delta + 4;
        }

        $hue *= 60;
        if ($hue < 0) {
            $hue += 360;
        }

        return [$hue, $saturation];
    }
}
