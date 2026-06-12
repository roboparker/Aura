<?php

namespace App\Command;

use App\Service\AccountExportPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual trigger for the account-export retention pass — same code path as
 * the nightly scheduler run (App\Message\PruneAccountExports), exposed as a
 * command for ad-hoc cleanup and ops runbooks:
 *
 *   bin/console app:account-exports:prune
 */
#[AsCommand(
    name: 'app:account-exports:prune',
    description: 'Delete account exports (zip + row) past the retention window.',
)]
final class AccountExportPruneCommand extends Command
{
    public function __construct(
        private AccountExportPruner $pruner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deleted = $this->pruner->prune();

        $io->success(sprintf('Deleted %d expired account export(s).', $deleted));

        return Command::SUCCESS;
    }
}
