<?php

namespace App\MessageHandler;

use App\Message\RunBackup;
use App\Service\BackupRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the backup pass when the scheduler fires App\Message\RunBackup
 * (nightly on the default schedule). A failure throws, so it lands in the
 * worker log as an error — and the next night's run starts fresh; backups
 * are independent of each other, so there is no catch-up state to manage.
 */
#[AsMessageHandler]
final class RunBackupHandler
{
    public function __construct(
        private BackupRunner $runner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunBackup $message): void
    {
        $result = $this->runner->run();

        $this->logger->info(sprintf(
            'Backup pass: wrote %s%s; pruned %d old file(s).',
            $result->dbFile,
            null !== $result->mediaFile ? ' and ' . $result->mediaFile : ' (no media dir, skipped archive)',
            count($result->pruned),
        ));
    }
}
