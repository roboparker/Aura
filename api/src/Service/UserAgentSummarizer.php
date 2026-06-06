<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Tiny best-effort User-Agent → "Browser on OS" summarizer for the active
 * sessions list. Deliberately a small heuristic, not a full UA database —
 * the label is cosmetic and a wrong guess on an exotic client is harmless.
 *
 * @phpstan-type UaSummary array{browser: string, os: string, label: string}
 */
final class UserAgentSummarizer
{
    /**
     * @return UaSummary
     */
    public function summarize(?string $ua): array
    {
        if (null === $ua || '' === trim($ua)) {
            return ['browser' => 'Unknown', 'os' => 'Unknown', 'label' => 'Unknown device'];
        }

        $browser = $this->browser($ua);
        $os = $this->os($ua);
        $label = 'Unknown' === $os ? $browser : sprintf('%s on %s', $browser, $os);

        return ['browser' => $browser, 'os' => $os, 'label' => $label];
    }

    private function browser(string $ua): string
    {
        // Order matters — Edge/Chrome both contain "Chrome", etc.
        $map = [
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'Firefox' => 'Firefox',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
        ];
        foreach ($map as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }
        return 'Unknown browser';
    }

    private function os(string $ua): string
    {
        $map = [
            'Windows' => 'Windows',
            'iPhone' => 'iOS',
            'iPad' => 'iPadOS',
            'Mac OS X' => 'macOS',
            'Macintosh' => 'macOS',
            'Android' => 'Android',
            'Linux' => 'Linux',
            'CrOS' => 'ChromeOS',
        ];
        foreach ($map as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }
        return 'Unknown';
    }
}
