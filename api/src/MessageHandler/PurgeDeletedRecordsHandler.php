<?php

namespace App\MessageHandler;

use App\Deletion\PurgeRunner;
use App\Message\PurgeDeletedRecords;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the nightly purge sweep. Idempotent — a double-fire finds nothing still
 * due, and a single failing record is logged and left due rather than stalling
 * the ones behind it.
 */
#[AsMessageHandler]
final class PurgeDeletedRecordsHandler
{
    public function __construct(
        private PurgeRunner $runner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeDeletedRecords $message): void
    {
        $result = $this->runner->run();

        if (0 !== array_sum($result)) {
            $this->logger->info(sprintf(
                'Deletion purge pass: %d organization(s), %d space(s), %d account(s), %d stale token(s).',
                $result['organizations'],
                $result['spaces'],
                $result['accounts'],
                $result['tokens'],
            ));
        }
    }
}
