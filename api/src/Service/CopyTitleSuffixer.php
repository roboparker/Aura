<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Appends the idempotent " (copy)" suffix used when cloning a titled entity
 * (board / page / discussion), truncating to fit the entity's title column.
 * Idempotent so repeated copies don't pile up "(copy) (copy) (copy)". Pulled
 * out of the three copy controllers that each carried an identical copy.
 */
final class CopyTitleSuffixer
{
    private const SUFFIX = ' (copy)';

    private function __construct()
    {
    }

    public static function apply(string $title, int $maxLength): string
    {
        if (str_ends_with($title, self::SUFFIX)) {
            return $title;
        }
        $combined = $title . self::SUFFIX;
        if (mb_strlen($combined) <= $maxLength) {
            return $combined;
        }
        // SUFFIX is a literal ' (copy)' (7 chars), so $maxLength - its length
        // is always positive for any realistic column width — no fallback branch.
        $room = $maxLength - mb_strlen(self::SUFFIX);
        return mb_substr($title, 0, $room) . self::SUFFIX;
    }
}
