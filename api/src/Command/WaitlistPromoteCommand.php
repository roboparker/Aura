<?php

namespace App\Command;

use App\Entity\User;
use App\Service\WaitlistPromoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Promote one or more waitlisted accounts to full users from the CLI.
 *
 *   bin/console app:waitlist:promote you@example.com
 *   bin/console app:waitlist:promote a@x.com b@x.com --no-email
 *
 * This is how you bootstrap the first admin on a fresh instance where waitlist
 * mode is on (the prod default): a waitlisted account holds only
 * ROLE_WAITLISTED — User::getRoles() ignores stored roles — so granting
 * ROLE_ADMIN alone changes nothing, and the admin promote endpoint is itself
 * ROLE_ADMIN-gated. Sequence:
 *
 *   1. Sign up through the PWA (lands on the waitlist).
 *   2. bin/console app:waitlist:promote you@example.com --no-email
 *   3. bin/console app:user:role add ROLE_ADMIN you@example.com
 *
 * Delegates to WaitlistPromoter::promoteOne() so the flag flip and the
 * best-effort access email behave exactly like the admin UI's grant button;
 * --no-email skips the email for the promote-yourself case.
 */
#[AsCommand(
    name: 'app:waitlist:promote',
    description: 'Promote one or more waitlisted users to full accounts by email.',
)]
final class WaitlistPromoteCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private WaitlistPromoter $promoter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('emails', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'One or more user emails')
            ->addOption('no-email', null, InputOption::VALUE_NONE, 'Skip the "access granted" email (e.g. when promoting your own bootstrap account)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sendEmail = true !== $input->getOption('no-email');

        /** @var list<string> $emails */
        $emails = $input->getArgument('emails');

        $rows = [];
        $promoted = 0;
        foreach ($emails as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null === $user) {
                $io->warning(sprintf('No user found for %s — skipped.', $email));
                continue;
            }

            if (!$user->isWaitlisted()) {
                $rows[] = [$email, 'already a full account'];
                continue;
            }

            $this->promoter->promoteOne($user, $sendEmail);
            ++$promoted;
            $rows[] = [$email, $sendEmail ? 'promoted' : 'promoted (email skipped)'];
        }

        if ([] === $rows) {
            $io->error('No matching users — nothing changed.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Promoted %d user(s) off the waitlist.', $promoted));
        $io->table(['Email', 'Result'], $rows);
        return Command::SUCCESS;
    }
}
