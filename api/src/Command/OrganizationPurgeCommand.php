<?php

namespace App\Command;

use App\Repository\OrganizationRepository;
use App\Service\OrganizationDeletionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual trigger for the organization purge — the same code path as the
 * nightly scheduler run (App\Message\PurgeDeletedOrganizations), exposed for
 * ops runbooks:
 *
 *   bin/console app:organizations:purge --dry-run
 *   bin/console app:organizations:purge
 *
 * `--dry-run` lists what *would* go without touching anything. This is a
 * cascading delete of whole organizations, so being able to look before
 * pulling the trigger is worth the extra flag.
 */
#[AsCommand(
    name: 'app:organizations:purge',
    description: 'Hard-delete organizations whose deletion grace period has lapsed.',
)]
final class OrganizationPurgeCommand extends Command
{
    public function __construct(
        private OrganizationDeletionService $deletion,
        private OrganizationRepository $organizations,
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
        $due = $this->organizations->findDueForPurge(new \DateTimeImmutable());

        if ([] === $due) {
            $io->success('No organizations are due for purge.');

            return Command::SUCCESS;
        }

        $io->table(
            ['id', 'name', 'deleted at', 'purge after'],
            array_map(static fn ($org) => [
                (string) $org->getId(),
                $org->getName(),
                $org->getDeletedAt()?->format('Y-m-d H:i') ?? '',
                $org->getPurgeAfter()?->format('Y-m-d H:i') ?? '',
            ], $due),
        );

        if (true === $input->getOption('dry-run')) {
            $io->note(sprintf('%d organization(s) would be purged. Re-run without --dry-run.', \count($due)));

            return Command::SUCCESS;
        }

        $purged = $this->deletion->purgeDue();
        $io->success(sprintf('Purged %d organization(s).', $purged));

        return Command::SUCCESS;
    }
}
