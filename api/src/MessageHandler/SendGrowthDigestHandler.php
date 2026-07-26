<?php

namespace App\MessageHandler;

use App\Message\SendGrowthDigest;
use App\Service\GrowthDigestMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends the weekly growth digest. Runs Mondays on the default schedule.
 *
 * A failure throws and lands in the worker log. Deliberately not retried into
 * correctness: a missed digest is a missed email, and the dashboard still has
 * the numbers — re-sending a stale week later would be worse than silence.
 */
#[AsMessageHandler]
final class SendGrowthDigestHandler
{
    public function __construct(
        private GrowthDigestMailer $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendGrowthDigest $message): void
    {
        $sent = $this->mailer->sendWeekly();
        $this->logger->info('Growth digest sent.', ['admins' => $sent]);
    }
}
