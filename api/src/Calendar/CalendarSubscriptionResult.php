<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * What a provider returns when we open a change-notification channel (#582
 * Phase D). `channelId` is our handle for it (Google channel id / Graph
 * subscription id); `resourceId` is Google's opaque resource handle (needed to
 * stop the channel; null for Graph); `expiresAt` is when it must be renewed.
 */
final class CalendarSubscriptionResult
{
    public function __construct(
        public readonly string $channelId,
        public readonly ?string $resourceId,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
