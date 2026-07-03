<?php

declare(strict_types=1);

namespace App\Service;

use App\Calendar\CalendarChange;
use App\Calendar\CalendarClientRegistry;
use App\Calendar\CalendarSyncException;
use App\Entity\CalendarEventLink;
use App\Entity\OAuthConnection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pulls changes from a connected calendar back into Aura (#582 Phase B).
 *
 * We reflect only events Aura created (matched by {@see CalendarEventLink}): if
 * the user drags one to another day on the provider, the task's due date
 * follows; if they delete it, the task's due date is cleared and the link
 * dropped. Arbitrary provider events are ignored — Aura stays the task system
 * of record. Task mutations go through the EntityManager directly (not the API
 * processors), so reflecting a change never bounces back out as a push.
 */
final class CalendarPuller
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarClientRegistry $clients,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function pull(OAuthConnection $connection, string $calendarId = 'primary'): void
    {
        $provider = $connection->getProvider();
        $client = $this->clients->clientFor($provider);
        if (null === $client) {
            // No sync client for this provider on this instance.
            return;
        }

        try {
            $page = $client->listChanges($connection, $calendarId, $connection->getCalendarSyncToken());
            if ($page->tokenInvalid) {
                // Stale cursor — drop it and do a fresh full sync now.
                $connection->setCalendarSyncToken(null);
                $page = $client->listChanges($connection, $calendarId, null);
            }
        } catch (CalendarSyncException $e) {
            $this->logger->warning('Calendar pull failed: {message}', ['message' => $e->getMessage()]);
            $this->em->flush();

            return;
        }

        foreach ($page->changes as $change) {
            $this->apply($connection, $provider, $change);
        }

        if (null !== $page->nextSyncToken) {
            $connection->setCalendarSyncToken($page->nextSyncToken);
        }
        $connection->setCalendarSyncedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function apply(OAuthConnection $connection, string $provider, CalendarChange $change): void
    {
        $link = $this->em->getRepository(CalendarEventLink::class)
            ->findOneBy(['provider' => $provider, 'externalEventId' => $change->eventId]);
        if (null === $link) {
            // Not an Aura-managed event — ignore.
            return;
        }
        $task = $link->getTask();
        if (null === $task) {
            $this->em->remove($link);

            return;
        }
        // A link should only ever point at the connection owner's task; guard
        // anyway so one account can never mutate another's task.
        $owner = $task->getOwner();
        if (null === $owner || true !== $owner->getId()?->equals($connection->getUser()?->getId())) {
            return;
        }

        if ($change->cancelled) {
            // Deleted on the provider → clear the due date and unlink.
            $task->setDueDate(null);
            $this->em->remove($link);

            return;
        }

        if (null === $change->date) {
            return;
        }

        $due = $task->getDueDate();
        if ($due?->format('Y-m-d') === $change->date->format('Y-m-d')) {
            // Unchanged (commonly the echo of our own push) — nothing to do.
            return;
        }

        // Conflict guard: don't let a provider state older than our last push
        // clobber a fresher Aura edit (e.g. a reschedule the provider hasn't
        // caught up to yet). A change with no timestamp is trusted.
        $lastPushed = $link->getLastPushedAt();
        if (null !== $lastPushed && null !== $change->updatedAt && $change->updatedAt <= $lastPushed) {
            return;
        }

        // Reflect the move, preserving the task's time-of-day when it had one.
        $hour = null !== $due ? (int) $due->format('H') : 0;
        $minute = null !== $due ? (int) $due->format('i') : 0;
        $second = null !== $due ? (int) $due->format('s') : 0;
        $task->setDueDate($change->date->setTime($hour, $minute, $second));
    }
}
