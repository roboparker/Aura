<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Calendar\CalendarClientInterface;
use App\Calendar\CalendarClientRegistry;
use App\Calendar\CalendarEvent;
use App\Calendar\CalendarSyncException;
use App\Entity\CalendarEventLink;
use App\Entity\OAuthConnection;
use App\Entity\Task;
use App\Entity\User;
use App\Message\SyncTaskToCalendar;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Pushes a task to every calendar the owner has connected (#582). An `upsert`
 * fans out across all of the owner's connected providers (one
 * {@see CalendarEventLink} per (task, provider)); a `delete` targets the one
 * provider whose event id was snapshotted before the task row was removed.
 *
 * No-ops when the owner hasn't connected an account, so it's safe to enqueue
 * for every dated task. A failed provider call is logged, not retried into
 * oblivion — the task write on the request path has already succeeded.
 */
#[AsMessageHandler]
final class SyncTaskToCalendarHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarClientRegistry $clients,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncTaskToCalendar $message): void
    {
        if (SyncTaskToCalendar::DELETE === $message->action) {
            $client = $this->clients->clientFor($message->provider);
            $connection = $this->connectionFor($message->ownerId, $message->provider);
            if (null !== $client && null !== $connection) {
                $this->handleDelete($message, $connection, $client);
            }

            return;
        }

        // upsert: reflect the task onto every calendar the owner has connected.
        $taskId = $message->taskId;
        if (null === $taskId || !Uuid::isValid($taskId)) {
            return;
        }
        $task = $this->em->getRepository(Task::class)->find(Uuid::fromString($taskId));
        if (null === $task) {
            return;
        }

        foreach ($this->clients->providers() as $provider) {
            $connection = $this->connectionFor($message->ownerId, $provider);
            $client = $this->clients->clientFor($provider);
            if (null === $connection || null === $client) {
                continue;
            }
            $this->upsert($task, $provider, $connection, $client);
        }
    }

    private function connectionFor(string $ownerId, string $provider): ?OAuthConnection
    {
        if (!Uuid::isValid($ownerId)) {
            return null;
        }
        $owner = $this->em->getRepository(User::class)->find(Uuid::fromString($ownerId));
        if (null === $owner) {
            return null;
        }

        return $this->em->getRepository(OAuthConnection::class)
            ->findOneBy(['user' => $owner, 'provider' => $provider]);
    }

    private function handleDelete(
        SyncTaskToCalendar $message,
        OAuthConnection $connection,
        CalendarClientInterface $client,
    ): void {
        $eventId = $message->eventId;
        if (null === $eventId || '' === $eventId) {
            return;
        }
        try {
            $client->deleteEvent($connection, $message->calendarId, $eventId);
        } catch (CalendarSyncException $e) {
            $this->logFailure($e, 'delete');
        }
    }

    private function upsert(
        Task $task,
        string $provider,
        OAuthConnection $connection,
        CalendarClientInterface $client,
    ): void {
        $link = $this->em->getRepository(CalendarEventLink::class)
            ->findOneBy(['task' => $task, 'provider' => $provider]);
        $due = $task->getDueDate();

        // A task that lost its due date should drop off the calendar.
        if (null === $due) {
            if (null !== $link) {
                $this->removeEvent($connection, $client, $link);
            }

            return;
        }

        // A midnight due time is a whole-day task → all-day event; any specific
        // time becomes a timed event in the owner's zone.
        $allDay = '00:00:00' === $due->format('H:i:s');
        $event = new CalendarEvent(
            $task->getTitle(),
            $task->getDescription(),
            $due,
            null !== $task->getCompletedOn(),
            $allDay,
            $this->ownerTimeZone($task),
            $task->getRecurrenceRule(),
            $this->exceptionDates($task),
        );

        if (null !== $link) {
            try {
                $client->updateEvent($connection, $link->getCalendarId(), $link->getExternalEventId(), $event);
                $link->markPushed();
                $this->em->flush();

                return;
            } catch (CalendarSyncException $e) {
                if (!$e->eventGone) {
                    $this->logFailure($e, 'update');

                    return;
                }
                // Removed on the provider side — drop the stale link and recreate.
                $this->em->remove($link);
                $this->em->flush();
            }
        }

        try {
            $eventId = $client->createEvent($connection, 'primary', $event);
        } catch (CalendarSyncException $e) {
            $this->logFailure($e, 'create');

            return;
        }

        $fresh = (new CalendarEventLink($provider))
            ->setTask($task)
            ->setCalendarId('primary')
            ->setExternalEventId($eventId)
            ->markPushed();
        $this->em->persist($fresh);
        $this->em->flush();
    }

    private function ownerTimeZone(Task $task): string
    {
        $tz = $task->getOwner()?->getPreferences()['timezone'] ?? null;

        return is_string($tz) && '' !== $tz ? $tz : 'UTC';
    }

    /**
     * The task's recurrence exceptions as EXDATE days.
     *
     * @return list<\DateTimeImmutable>
     */
    private function exceptionDates(Task $task): array
    {
        $dates = [];
        foreach ($task->getRecurrenceExceptions() as $ymd) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
            if (false !== $parsed) {
                $dates[] = $parsed;
            }
        }

        return $dates;
    }

    private function removeEvent(
        OAuthConnection $connection,
        CalendarClientInterface $client,
        CalendarEventLink $link,
    ): void {
        try {
            $client->deleteEvent($connection, $link->getCalendarId(), $link->getExternalEventId());
        } catch (CalendarSyncException $e) {
            if (!$e->eventGone) {
                $this->logFailure($e, 'delete');
            }
        }
        $this->em->remove($link);
        $this->em->flush();
    }

    private function logFailure(CalendarSyncException $e, string $op): void
    {
        $this->logger->warning('Calendar sync {op} failed: {message}', [
            'op' => $op,
            'message' => $e->getMessage(),
        ]);
    }
}
