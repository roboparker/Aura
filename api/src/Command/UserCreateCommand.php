<?php

namespace App\Command;

use App\Service\AdminUserCreator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Create a ready-to-sign-in account from the CLI.
 *
 *   bin/console app:user:create you@example.com
 *   bin/console app:user:create qa@example.com --with-space="QA Studio"
 *   bin/console app:user:create demo@example.com --given-name=Demo --family-name=User
 *
 * Clears both signup gates (email confirmation + waitlist) so the account works
 * immediately — which is what makes this usable on an instance whose signups are
 * closed, e.g. standing up a test account on production to smoke-test billing.
 * Pass --waitlisted to leave it on the waitlist instead.
 *
 * The generated password is printed **once** and never stored in plaintext.
 * Pairs with {@see WaitlistPromoteCommand} and {@see UserRoleCommand}; shares
 * {@see AdminUserCreator} with the admin UI so both paths behave identically.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Create a sign-in-ready user account (skips email confirmation + waitlist).',
)]
final class UserCreateCommand extends Command
{
    public function __construct(private AdminUserCreator $creator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address for the new account')
            ->addOption('given-name', null, InputOption::VALUE_REQUIRED, 'First name', 'Test')
            ->addOption('family-name', null, InputOption::VALUE_REQUIRED, 'Last name', 'User')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Set an explicit password (default: generate a strong one)')
            ->addOption('with-space', null, InputOption::VALUE_REQUIRED, 'Also create a shared space with this name, user as admin')
            ->addOption('waitlisted', null, InputOption::VALUE_NONE, 'Leave the account on the waitlist instead of promoting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Provide a valid email address.');
            return Command::FAILURE;
        }

        $given = $input->getOption('given-name');
        $family = $input->getOption('family-name');
        $password = $input->getOption('password');
        $space = $input->getOption('with-space');

        try {
            $result = $this->creator->create(
                $email,
                $given,
                $family,
                is_string($password) ? $password : null,
                true !== $input->getOption('waitlisted'),
                is_string($space) ? $space : null,
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $createdSpace = $result['space'];

        $rows = [
            ['Email', $email],
            ['Password', $result['password']],
            ['Waitlisted', $result['user']->isWaitlisted() ? 'yes' : 'no'],
        ];
        if (null !== $createdSpace) {
            $rows[] = ['Shared space', $createdSpace->getName()];
        }

        $io->success(sprintf('Created %s.', $email));
        $io->table(['Field', 'Value'], $rows);
        $io->warning('The password is shown once — copy it now.');

        return Command::SUCCESS;
    }
}
