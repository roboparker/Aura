<?php

namespace App\Command;

use App\Service\TaskReminderDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console entry point for App\Service\TaskReminderDispatcher — see that
 * service for the actual walk-and-notify logic.
 *
 * In production the same pass runs every 5 minutes through the scheduler
 * (App\Scheduler\MainScheduleProvider, consumed by the messenger worker);
 * this command remains for manual / one-off runs. Safe to run by hand at
 * any time — idempotency lives in the service, with no state outside the
 * notification table.
 */
#[AsCommand(
    name: 'app:tasks:reminders:dispatch',
    description: 'Create in-app notifications for any task reminders that have come due.',
)]
final class DispatchTaskRemindersCommand extends Command
{
    public function __construct(private TaskReminderDispatcher $dispatcher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->dispatcher->dispatch();

        $io->success(sprintf(
            'Created %d notification(s); sent %d email(s); delivered %d push(es).',
            $result->created,
            $result->emailsSent,
            $result->pushesSent,
        ));
        return Command::SUCCESS;
    }
}
