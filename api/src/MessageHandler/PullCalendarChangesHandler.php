<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Calendar\CalendarClientRegistry;
use App\Entity\OAuthConnection;
use App\Message\PullCalendarChanges;
use App\Service\CalendarPuller;
use App\Service\CalendarSubscriptionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Pulls calendar changes (#582 Phase B/C/D). A targeted message (from a
 * webhook) pulls one connection; the scheduled sweep pulls every syncable
 * connection and also ensures + renews change-notification subscriptions.
 * {@see CalendarPuller} swallows per-connection failures so one bad token can't
 * stall the rest.
 */
#[AsMessageHandler]
final class PullCalendarChangesHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarPuller $puller,
        private readonly CalendarClientRegistry $clients,
        private readonly CalendarSubscriptionManager $subscriptions,
    ) {
    }

    public function __invoke(PullCalendarChanges $message): void
    {
        // Targeted pull (webhook): just the named connection.
        if (null !== $message->connectionId) {
            if (!Uuid::isValid($message->connectionId)) {
                return;
            }
            $connection = $this->em->getRepository(OAuthConnection::class)
                ->find(Uuid::fromString($message->connectionId));
            if (null !== $connection) {
                $this->puller->pull($connection);
            }

            return;
        }

        // Scheduled sweep: pull everything, then keep subscriptions healthy.
        $connections = $this->em->getRepository(OAuthConnection::class)
            ->findBy(['provider' => $this->clients->providers()]);
        foreach ($connections as $connection) {
            $this->puller->pull($connection);
            $this->subscriptions->ensure($connection);
        }
        $this->subscriptions->renewExpiring();
    }
}
