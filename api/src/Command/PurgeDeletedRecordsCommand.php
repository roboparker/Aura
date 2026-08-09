<?php

namespace App\Command;

use App\Deletion\PurgeRunner;
use App\Repository\OrganizationRepository;
use App\Repository\SpaceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual trigger for the deletion purge — the same code path as the nightly
 * scheduler run (App\Message\PurgeDeletedRecords), exposed for ops runbooks:
 *
 *   bin/console app:deletions:purge --dry-run
 *   bin/console app:deletions:purge
 *
 * `--dry-run` lists what would go without touching anything. This cascades
 * whole organizations, spaces, and accounts, so being able to look before
 * pulling the trigger is worth the extra flag.
 */
#[AsCommand(
    name: 'app:deletions:purge',
    description: 'Hard-delete organizations, spaces, and accounts past their deletion grace period.',
)]
final class PurgeDeletedRecordsCommand extends Command
{
    public function __construct(
        private PurgeRunner $runner,
        private OrganizationRepository $organizations,
        private SpaceRepository $spaces,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be purged, then stop.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        if (true === $input->getOption('dry-run')) {
            $rows = [];
            foreach ($this->organizations->findDueForPurge($now) as $org) {
                $rows[] = ['organization', $org->getName(), $org->getPurgeAfter()?->format('Y-m-d H:i') ?? ''];
            }
            foreach ($this->spaces->findDueForPurge($now) as $space) {
                $rows[] = ['space', $space->getName(), $space->getPurgeAfter()?->format('Y-m-d H:i') ?? ''];
            }
            if ([] === $rows) {
                $io->success('Nothing is due for purge.');

                return Command::SUCCESS;
            }
            $io->table(['type', 'name', 'purge after'], $rows);
            $io->note(sprintf('%d record(s) would be purged (accounts omitted — emails are PII).', count($rows)));

            return Command::SUCCESS;
        }

        $result = $this->runner->run($now);
        $io->success(sprintf(
            'Purged %d organization(s), %d space(s), %d account(s); swept %d stale restore token(s).',
            $result['organizations'],
            $result['spaces'],
            $result['accounts'],
            $result['tokens'],
        ));

        return Command::SUCCESS;
    }
}
