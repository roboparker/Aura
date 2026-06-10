<?php

namespace App\MessageHandler;

use App\Message\DispatchTaskReminders;
use App\Service\TaskReminderDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the task-reminder pass when the scheduler fires
 * App\Message\DispatchTaskReminders (every 5 minutes on the default
 * schedule). The dispatch is idempotent, so a retry after a transient
 * failure can't double-notify.
 */
#[AsMessageHandler]
final class DispatchTaskRemindersHandler
{
    public function __construct(
        private TaskReminderDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DispatchTaskReminders $message): void
    {
        $result = $this->dispatcher->dispatch();

        $this->logger->info(sprintf(
            'Task reminder pass: created %d notification(s); sent %d email(s); delivered %d push(es).',
            $result->created,
            $result->emailsSent,
            $result->pushesSent,
        ));
    }
}
