<?php

namespace App\Command;

use App\Calendar\CalendarClientRegistry;
use App\Entity\OAuthConnection;
use App\Service\CalendarPuller;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console entry point for a calendar-pull pass (#582 Phase B) — reflect
 * provider-side moves/deletions of Aura-managed events back onto their tasks.
 *
 * In production the same pass runs every 15 minutes through the scheduler
 * (App\Scheduler\MainScheduleProvider); this remains for manual / one-off
 * runs. Idempotent — safe to run by hand at any time.
 */
#[AsCommand(
    name: 'app:calendar:pull',
    description: 'Pull provider calendar changes back onto Aura tasks.',
)]
final class PullCalendarChangesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private CalendarPuller $puller,
        private CalendarClientRegistry $clients,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connections = $this->em->getRepository(OAuthConnection::class)
            ->findBy(['provider' => $this->clients->providers()]);
        foreach ($connections as $connection) {
            $this->puller->pull($connection);
        }

        $io->success(sprintf('Pulled calendar changes for %d connection(s).', count($connections)));

        return Command::SUCCESS;
    }
}
