<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\OAuthConnection;

/**
 * Opening + closing provider change-notification channels (#582 Phase D), so a
 * provider-side edit triggers a near-real-time pull instead of waiting for the
 * 15-minute poll. Separate from {@see CalendarClientInterface} because it's an
 * optional capability; the subscription manager checks `instanceof` before use.
 */
interface CalendarSubscriberInterface
{
    /**
     * Open a channel delivering change notifications to $notificationUrl.
     * $secret is echoed back by the provider so we can authenticate callbacks.
     *
     * @throws CalendarSyncException when the provider call fails or no token
     */
    public function subscribe(
        OAuthConnection $connection,
        string $notificationUrl,
        string $secret,
    ): CalendarSubscriptionResult;

    /**
     * Stop a previously-opened channel. Best-effort — a provider error is the
     * caller's to swallow (the channel expires on its own regardless).
     *
     * @throws CalendarSyncException when the provider call fails or no token
     */
    public function unsubscribe(OAuthConnection $connection, string $channelId, ?string $resourceId): void;
}
