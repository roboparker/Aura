<?php

namespace App\Command;

use App\Entity\Segment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manage platform roles on one or more users from the CLI.
 *
 *   bin/console app:user:role add    ROLE_ADMIN  you@example.com
 *   bin/console app:user:role remove ROLE_ADMIN  a@x.com b@x.com
 *   bin/console app:user:role set    ROLE_ADMIN  you@example.com
 *
 * This is how you bootstrap the first admin (no admin UI grants ROLE_ADMIN).
 * The `ROLE_` prefix is added if you omit it, so `add admin you@x.com` works.
 *
 * ROLE_USER is implicit (User::getRoles always appends it) and ROLE_WAITLISTED
 * is owned by the waitlist toggle, so both are rejected here.
 */
#[AsCommand(
    name: 'app:user:role',
    description: 'Add, remove, or set a role on one or more users by email.',
)]
final class UserRoleCommand extends Command
{
    private const ACTIONS = ['add', 'remove', 'set'];
    private const RESERVED_ROLES = ['ROLE_USER', 'ROLE_WAITLISTED'];

    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'add | remove | set')
            ->addArgument('role', InputArgument::REQUIRED, 'Role name (e.g. ROLE_ADMIN; the ROLE_ prefix is added if missing)')
            ->addArgument('emails', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'One or more user emails');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $action = $input->getArgument('action');
        if (!in_array($action, self::ACTIONS, true)) {
            $io->error(sprintf('Action must be one of: %s.', implode(', ', self::ACTIONS)));
            return Command::INVALID;
        }

        $role = $this->normalizeRole($input->getArgument('role'));
        if (in_array($role, self::RESERVED_ROLES, true)) {
            $io->error(sprintf(
                '%s is managed automatically and can\'t be set with this command.',
                $role,
            ));
            return Command::INVALID;
        }
        // Segment roles (ROLE_SEGMENT_*) are computed from segment membership
        // at request time, never stored on the user. Manage them with
        // `app:segment add|remove` instead — storing one here would be a
        // no-op the SegmentVoter ignores.
        if (str_starts_with($role, Segment::ROLE_PREFIX)) {
            $io->error(sprintf(
                '%s is a segment role — manage it with `app:segment add|remove <key> <email>`.',
                $role,
            ));
            return Command::INVALID;
        }

        /** @var list<string> $emails */
        $emails = $input->getArgument('emails');

        $rows = [];
        $touched = 0;
        foreach ($emails as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null === $user) {
                $io->warning(sprintf('No user found for %s — skipped.', $email));
                continue;
            }

            $user->setRoles($this->apply($action, $role, $this->storedRoles($user)));
            ++$touched;
            $rows[] = [$email, implode(', ', $user->getRoles())];
        }

        if (0 === $touched) {
            $io->error('No matching users — nothing changed.');
            return Command::FAILURE;
        }

        $this->em->flush();

        $io->success(sprintf('%s %s on %d user(s).', ucfirst($action), $role, $touched));
        $io->table(['Email', 'Roles'], $rows);
        return Command::SUCCESS;
    }

    /**
     * Stored (non-implicit) roles for a user. User::getRoles() injects
     * ROLE_USER (or, for a waitlisted account, ROLE_WAITLISTED); strip those
     * back out so we mutate only the persisted set. A freshly-waitlisted
     * signup has no stored roles, so this round-trips correctly.
     *
     * @return list<string>
     */
    private function storedRoles(User $user): array
    {
        return array_values(array_filter(
            $user->getRoles(),
            static fn (string $r) => !in_array($r, self::RESERVED_ROLES, true),
        ));
    }

    /**
     * @param list<string> $stored
     * @return list<string>
     */
    private function apply(string $action, string $role, array $stored): array
    {
        return match ($action) {
            'add' => array_values(array_unique([...$stored, $role])),
            'remove' => array_values(array_filter($stored, static fn (string $r) => $r !== $role)),
            'set' => [$role],
            default => $stored,
        };
    }

    private function normalizeRole(string $role): string
    {
        $role = strtoupper(trim($role));
        if (!str_starts_with($role, 'ROLE_')) {
            $role = 'ROLE_' . $role;
        }
        return $role;
    }
}
