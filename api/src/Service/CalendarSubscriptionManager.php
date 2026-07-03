<?php

declare(strict_types=1);

namespace App\Service;

use App\Calendar\CalendarClientRegistry;
use App\Calendar\CalendarSubscriberInterface;
use App\Calendar\CalendarSyncException;
use App\Entity\CalendarSubscription;
use App\Entity\OAuthConnection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Opens + renews provider change-notification channels (#582 Phase D), so a
 * provider-side edit triggers a near-real-time pull instead of waiting for the
 * 15-minute poll.
 *
 * Dark-launched: with no `app.calendar_webhook_base_url` configured (the
 * default) every method is a no-op, so the poll-based two-way sync (Phase B/C)
 * keeps working with zero provider-callback setup. Wired into the pull cron —
 * {@see \App\MessageHandler\PullCalendarChangesHandler} calls {@see ensure()}
 * per connection each sweep, so channels are created shortly after a user
 * connects and renewed before expiry with no connect-time coupling.
 */
final class CalendarSubscriptionManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarClientRegistry $clients,
        private readonly LoggerInterface $logger,
        private readonly string $webhookBaseUrl,
    ) {
    }

    public function enabled(): bool
    {
        return '' !== $this->webhookBaseUrl;
    }

    /**
     * Ensure a healthy subscription exists for the connection, creating or
     * renewing one when absent or within a day of expiry.
     */
    public function ensure(OAuthConnection $connection): void
    {
        if (!$this->enabled()) {
            return;
        }
        $provider = $connection->getProvider();
        $client = $this->clients->clientFor($provider);
        if (!$client instanceof CalendarSubscriberInterface) {
            return;
        }

        $existing = $this->em->getRepository(CalendarSubscription::class)->findOneBy(['connection' => $connection]);
        if (null !== $existing && $existing->getExpiresAt() > new \DateTimeImmutable('+1 day')) {
            return;
        }

        $secret = bin2hex(random_bytes(24));
        $url = rtrim($this->webhookBaseUrl, '/') . '/calendar/webhook/' . $provider;
        try {
            $result = $client->subscribe($connection, $url, $secret);
        } catch (CalendarSyncException $e) {
            $this->logger->warning('Calendar subscribe failed: {message}', ['message' => $e->getMessage()]);

            return;
        }

        // Stop the channel we're replacing (best-effort) before overwriting it.
        if (null !== $existing) {
            $this->stop($client, $connection, $existing);
        }

        $subscription = $existing ?? new CalendarSubscription($provider);
        $subscription
            ->setConnection($connection)
            ->setProvider($provider)
            ->setChannelId($result->channelId)
            ->setResourceId($result->resourceId)
            ->setSecret($secret)
            ->setExpiresAt($result->expiresAt);
        $this->em->persist($subscription);
        $this->em->flush();
    }

    /** Renew every subscription within a day of expiry. */
    public function renewExpiring(): void
    {
        if (!$this->enabled()) {
            return;
        }
        $subscriptions = $this->em->getRepository(CalendarSubscription::class)->createQueryBuilder('s')
            ->where('s.expiresAt <= :soon')
            ->setParameter('soon', new \DateTimeImmutable('+1 day'))
            ->getQuery()
            ->getResult();
        foreach ($subscriptions as $subscription) {
            $connection = $subscription->getConnection();
            if (null !== $connection) {
                $this->ensure($connection);
            }
        }
    }

    /** Best-effort teardown of a connection's channel (used on disconnect). */
    public function remove(OAuthConnection $connection): void
    {
        $subscription = $this->em->getRepository(CalendarSubscription::class)
            ->findOneBy(['connection' => $connection]);
        if (null === $subscription) {
            return;
        }
        $client = $this->clients->clientFor($connection->getProvider());
        if ($client instanceof CalendarSubscriberInterface) {
            $this->stop($client, $connection, $subscription);
        }
        $this->em->remove($subscription);
        $this->em->flush();
    }

    private function stop(
        CalendarSubscriberInterface $client,
        OAuthConnection $connection,
        CalendarSubscription $subscription,
    ): void {
        try {
            $client->unsubscribe($connection, $subscription->getChannelId(), $subscription->getResourceId());
        } catch (CalendarSyncException $e) {
            $this->logger->warning('Calendar unsubscribe failed: {message}', ['message' => $e->getMessage()]);
        }
    }
}
