<?php

namespace App\MessageHandler;

use App\Message\DispatchNotificationDigest;
use App\Service\NotificationDigestDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends the notification digests when the scheduler fires
 * App\Message\DispatchNotificationDigest (hourly at minute 55, daily at
 * 08:00 UTC on the default schedule). Already-digested rows carry a
 * `digestedAt` stamp, so a retry after a transient failure can't resend.
 */
#[AsMessageHandler]
final class DispatchNotificationDigestHandler
{
    public function __construct(
        private NotificationDigestDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DispatchNotificationDigest $message): void
    {
        $result = $this->dispatcher->dispatch($message->getPeriod());

        $this->logger->info(sprintf(
            'Notification digest pass (%s): sent %d digest(s) covering %d notification(s).',
            $message->getPeriod(),
            $result->emailsSent,
            $result->rowsDigested,
        ));
    }
}
