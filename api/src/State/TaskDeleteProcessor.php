<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\CalendarEventLink;
use App\Entity\Task;
use App\Entity\User;
use App\Message\SyncTaskToCalendar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Wraps the default ORM remove processor on `DELETE /tasks/{id}` so a deleted
 * task's calendar events are removed from the owner's connected calendars
 * (#582). The {@see CalendarEventLink} rows CASCADE-delete with the task, so we
 * snapshot each event id into a delete message *before* removing, then dispatch
 * after the row is gone.
 *
 * @implements ProcessorInterface<Task, mixed>
 */
final class TaskDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Task, mixed> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param Task $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $messages = [];
        $owner = $data->getOwner();
        if ($owner instanceof User) {
            $ownerId = (string) $owner->getId();
            $links = $this->em->getRepository(CalendarEventLink::class)->findBy(['task' => $data]);
            foreach ($links as $link) {
                $messages[] = SyncTaskToCalendar::delete(
                    $ownerId,
                    $link->getExternalEventId(),
                    $link->getCalendarId(),
                    $link->getProvider(),
                );
            }
        }

        $result = $this->removeProcessor->process($data, $operation, $uriVariables, $context);

        foreach ($messages as $message) {
            $this->bus->dispatch($message);
        }

        return $result;
    }
}
