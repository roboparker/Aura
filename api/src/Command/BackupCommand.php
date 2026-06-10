<?php

namespace App\Command;

use App\Service\BackupRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console entry point for App\Service\BackupRunner — see that service for
 * the dump/archive/prune logic.
 *
 * In production the same pass runs nightly through the scheduler
 * (App\Scheduler\MainScheduleProvider, consumed by the messenger worker),
 * and scripts/deploy.sh invokes this command in a one-off container of the
 * incoming image before every container swap. Safe to run by hand at any
 * time — each run produces an independent timestamped pair of files.
 */
#[AsCommand(
    name: 'app:backup:run',
    description: 'Dump the database and archive uploaded media, pruning old backups beyond the retention cap.',
)]
final class BackupCommand extends Command
{
    public function __construct(private BackupRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->runner->run();

        $lines = ['Database: ' . $result->dbFile];
        $lines[] = null !== $result->mediaFile
            ? 'Media: ' . $result->mediaFile
            : 'Media: skipped (no media directory)';
        if ([] !== $result->pruned) {
            $lines[] = sprintf('Pruned %d old file(s): %s', count($result->pruned), implode(', ', $result->pruned));
        }

        $io->success(implode("\n", $lines));

        return Command::SUCCESS;
    }
}
