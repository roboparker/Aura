<?php

namespace App\Command;

use App\Service\SpaceExportPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual trigger for the space-export retention pass — same code path as
 * the nightly scheduler run (App\Message\PruneSpaceExports), exposed as a
 * command for ad-hoc cleanup and ops runbooks:
 *
 *   bin/console app:space-exports:prune
 */
#[AsCommand(
    name: 'app:space-exports:prune',
    description: 'Delete space exports (zip + row) past the retention window.',
)]
final class SpaceExportPruneCommand extends Command
{
    public function __construct(
        private SpaceExportPruner $pruner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deleted = $this->pruner->prune();

        $io->success(sprintf('Deleted %d expired space export(s).', $deleted));

        return Command::SUCCESS;
    }
}
