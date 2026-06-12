<?php

namespace App\MessageHandler;

use App\Message\PruneAccountExports;
use App\Service\AccountExportPruner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneAccountExportsHandler
{
    public function __construct(
        private AccountExportPruner $pruner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PruneAccountExports $message): void
    {
        $deleted = $this->pruner->prune();
        $this->logger->info(sprintf('Account export prune pass: deleted %d expired export(s).', $deleted));
    }
}
