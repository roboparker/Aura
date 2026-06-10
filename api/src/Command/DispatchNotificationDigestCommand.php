<?php

namespace App\Command;

use App\Service\NotificationDigestDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console entry point for App\Service\NotificationDigestDispatcher — see
 * that service for the roll-up-and-email logic.
 *
 * In production the same pass runs through the scheduler
 * (App\Scheduler\MainScheduleProvider, consumed by the messenger worker):
 * `--period=hourly` at minute 55, `--period=daily` at 08:00 UTC. This
 * command remains for manual / one-off runs; reruns inside the same
 * window are no-ops thanks to the `digestedAt` stamp.
 */
#[AsCommand(
    name: 'app:notifications:dispatch-digest',
    description: 'Group pending notifications into a single digest email per recipient.',
)]
final class DispatchNotificationDigestCommand extends Command
{
    public function __construct(private NotificationDigestDispatcher $dispatcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'period',
            null,
            InputOption::VALUE_REQUIRED,
            'Which digest cadence to send for: hourly or daily.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $period = (string) $input->getOption('period');
        if (!in_array($period, NotificationDigestDispatcher::ALLOWED_PERIODS, true)) {
            $io->error(sprintf(
                'Option --period must be one of: %s.',
                implode(', ', NotificationDigestDispatcher::ALLOWED_PERIODS),
            ));
            return Command::INVALID;
        }

        $result = $this->dispatcher->dispatch($period);

        $io->success(sprintf(
            'Sent %d %s digest(s) covering %d notification(s).',
            $result->emailsSent,
            $period,
            $result->rowsDigested,
        ));
        return Command::SUCCESS;
    }
}
