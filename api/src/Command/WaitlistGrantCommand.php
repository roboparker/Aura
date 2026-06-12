<?php

namespace App\Command;

use App\Entity\User;
use App\Service\WaitlistGrantService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grant waitlisted accounts full access from the CLI — the scriptable, SSH-able
 * companion to the /admin/waitlist "Grant access" button. Both surfaces share
 * {@see WaitlistGrantService}, so selection + promotion behave identically.
 *
 *   bin/console app:waitlist:grant a@x.com b@x.com         # by email
 *   bin/console app:waitlist:grant 0190f3...               # by user id
 *   bin/console app:waitlist:grant a@x.com 0190f3...       # ids and emails mixed
 *   bin/console app:waitlist:grant --oldest=25             # the 25 longest-waiting
 *   bin/console app:waitlist:grant --all                   # everyone on the list
 *   bin/console app:waitlist:grant --all --dry-run         # preview, change nothing
 *
 * Exactly one selection mode per run: explicit ids/emails, --oldest=N, or --all.
 * Selecting a user who already has full access is a harmless no-op (reported as
 * skipped), so a stale list or a double-run never re-sends an email.
 */
#[AsCommand(
    name: 'app:waitlist:grant',
    description: 'Promote waitlisted users to full accounts (and email them) from the CLI.',
)]
final class WaitlistGrantCommand extends Command
{
    public function __construct(private WaitlistGrantService $granter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'selectors',
                InputArgument::IS_ARRAY,
                'User ids and/or emails to grant access to',
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Promote every waitlisted user')
            ->addOption('oldest', null, InputOption::VALUE_REQUIRED, 'Promote the oldest N waitlisted users')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show who would be promoted without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $selectors */
        $selectors = $input->getArgument('selectors');
        $all = true === $input->getOption('all');
        $oldest = $input->getOption('oldest');
        $dryRun = true === $input->getOption('dry-run');

        // Exactly one selection mode — explicit selectors, --oldest, or --all.
        $modes = ([] !== $selectors ? 1 : 0) + ($all ? 1 : 0) + (null !== $oldest ? 1 : 0);
        if (1 !== $modes) {
            $io->error('Choose exactly one of: <selectors>, --oldest=N, or --all.');
            return Command::INVALID;
        }

        if ($all) {
            $candidates = $this->granter->findWaitlisted();
        } elseif (null !== $oldest) {
            if (!is_string($oldest) || 1 !== preg_match('/^\d+$/', $oldest) || (int) $oldest < 1) {
                $io->error('--oldest must be a positive integer.');
                return Command::INVALID;
            }
            $candidates = $this->granter->findWaitlisted((int) $oldest);
        } else {
            $resolved = $this->granter->resolveSelectors($selectors);
            foreach ($resolved['notFound'] as $selector) {
                $io->warning(sprintf('No user found for "%s" — skipped.', $selector));
            }
            $candidates = $resolved['users'];
        }

        if ([] === $candidates) {
            $io->warning('No matching users — nothing to do.');
            return Command::SUCCESS;
        }

        $result = $this->granter->grant($candidates, $dryRun);

        foreach ($result->skipped as $user) {
            $io->writeln(sprintf('  <comment>skip</comment> %s — already has full access.', $user->getEmail()));
        }

        if ([] === $result->promoted) {
            $io->warning('No waitlisted users in the selection — nothing changed.');
            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Email'],
            array_map(static fn (User $u): array => [(string) $u->getId(), $u->getEmail()], $result->promoted),
        );

        $io->success(sprintf(
            '%s %d user(s).%s',
            $dryRun ? 'Would promote' : 'Promoted',
            count($result->promoted),
            $dryRun ? ' Dry run — no changes made.' : '',
        ));

        return Command::SUCCESS;
    }
}
