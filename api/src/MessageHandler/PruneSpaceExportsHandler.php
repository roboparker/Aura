<?php

namespace App\MessageHandler;

use App\Message\PruneSpaceExports;
use App\Service\SpaceExportPruner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the export retention pass when the scheduler fires
 * App\Message\PruneSpaceExports (nightly on the default schedule). The
 * pass is idempotent — a double-fire just finds nothing left to delete.
 */
#[AsMessageHandler]
final class PruneSpaceExportsHandler
{
    public function __construct(
        private SpaceExportPruner $pruner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PruneSpaceExports $message): void
    {
        $deleted = $this->pruner->prune();

        $this->logger->info(sprintf('Space export prune pass: deleted %d expired export(s).', $deleted));
    }
}
