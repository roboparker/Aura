<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\CalendarEventLink;
use App\Entity\Task;
use App\Entity\User;
use App\Automation\AutomationEvents;
use App\Automation\TaskSnapshot;
use App\Message\RunAutomations;
use App\Message\SyncTaskToCalendar;
use App\Repository\AutomationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
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
        private AutomationRepository $automations,
        private Security $security,
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

        // Everything the rules will need has to be read *now* — after the
        // remove there is no row to read it from. Dispatched after, so a rule
        // never fires for a deletion that failed.
        $board = $data->getBoard();
        $automationPayload = null;
        // Skip the queue entirely when the board has no rule listening — the
        // same cheap check AutomationDispatcher makes, which can't be reused
        // here because it resolves the board from a task that's about to stop
        // existing.
        if (
            null !== $board
            && null !== $data->getId()
            && [] !== $this->automations->enabledFor($board, AutomationEvents::TASK_DELETED)
        ) {
            $actor = $this->security->getUser();
            $automationPayload = new RunAutomations(
                taskId: (string) $data->getId(),
                event: AutomationEvents::TASK_DELETED,
                before: TaskSnapshot::of($data),
                after: [],
                actorId: $actor instanceof User && null !== $actor->getId() ? (string) $actor->getId() : null,
                boardId: (string) $board->getId(),
                taskTitle: $data->getTitle(),
            );
        }

        $result = $this->removeProcessor->process($data, $operation, $uriVariables, $context);

        if (null !== $automationPayload) {
            $this->bus->dispatch($automationPayload);
        }

        foreach ($messages as $message) {
            $this->bus->dispatch($message);
        }

        return $result;
    }
}
