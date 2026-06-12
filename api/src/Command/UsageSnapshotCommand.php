<?php

namespace App\Command;

use App\Service\UsageSnapshotBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually capture a per-user usage snapshot (the nightly scheduler runs the
 * same App\Service\UsageSnapshotBuilder).
 *
 *   bin/console app:usage:snapshot                 # roll up yesterday (UTC)
 *   bin/console app:usage:snapshot --date=2026-06-10
 */
#[AsCommand(
    name: 'app:usage:snapshot',
    description: 'Roll up per-user disk/db/call usage into a daily snapshot.',
)]
final class UsageSnapshotCommand extends Command
{
    public function __construct(
        private UsageSnapshotBuilder $builder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'date',
            null,
            InputOption::VALUE_REQUIRED,
            'Day to capture as YYYY-MM-DD (UTC). Defaults to yesterday.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $day = null;
        $dateOption = $input->getOption('date');
        if (is_string($dateOption) && '' !== $dateOption) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateOption, new \DateTimeZone('UTC'));
            if (false === $parsed) {
                $io->error(sprintf('Invalid --date "%s" — expected YYYY-MM-DD.', $dateOption));
                return Command::FAILURE;
            }
            $day = $parsed;
        }

        $written = $this->builder->capture($day);

        $io->success(sprintf(
            'Captured usage snapshot for %s — %d user row(s).',
            ($day ?? new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->format('Y-m-d'),
            $written,
        ));

        return Command::SUCCESS;
    }
}
