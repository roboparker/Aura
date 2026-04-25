<?php

namespace App\Tests\Service;

use App\Service\AvatarColorService;
use PHPUnit\Framework\TestCase;

class AvatarColorServiceTest extends TestCase
{
    public function testPickReturnsPaletteColor(): void
    {
        $service = new AvatarColorService();
        $color = $service->pick();
        $this->assertContains($color, AvatarColorService::PALETTE);
    }

    /**
     * Every palette color must meet WCAG AA contrast (>= 4.5:1) against
     * white, so white text on top is legible. If you add a color and this
     * test fails, the color is too light.
     */
    public function testEveryPaletteColorMeetsWcagAaContrastAgainstWhite(): void
    {
        foreach (AvatarColorService::PALETTE as $hex) {
            $ratio = self::contrastAgainstWhite($hex);
            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                sprintf('Color %s contrast %.2f < 4.5:1 against white.', $hex, $ratio),
            );
        }
    }

    public function testPaletteHasAtLeastSixteenDistinctColors(): void
    {
        $this->assertGreaterThanOrEqual(16, count(AvatarColorService::PALETTE));
        $this->assertSame(count(AvatarColorService::PALETTE), count(array_unique(AvatarColorService::PALETTE)));
    }

    private static function contrastAgainstWhite(string $hex): float
    {
        $rgb = sscanf($hex, '#%02x%02x%02x');
        [$r, $g, $b] = array_map(self::channelLuminance(...), $rgb);
        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        return (1.0 + 0.05) / ($luminance + 0.05);
    }

    private static function channelLuminance(int $channel): float
    {
        $c = $channel / 255.0;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
}
