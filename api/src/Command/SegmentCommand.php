<?php

namespace App\Command;

use App\Entity\Segment;
use App\Entity\SegmentMembership;
use App\Entity\User;
use App\Repository\SegmentRepository;
use App\Service\SegmentEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Manage user segments from the CLI — the scriptable companion to the
 * /admin/segments UI for A/B tests, beta cohorts, and rollouts.
 *
 *   bin/console app:segment list
 *   bin/console app:segment show     beta-new-editor
 *   bin/console app:segment create   beta-new-editor --name="New editor" --rollout=20 --roles=ROLE_ADMIN
 *   bin/console app:segment rollout  beta-new-editor 50
 *   bin/console app:segment roles    beta-new-editor ROLE_ADMIN,ROLE_USER
 *   bin/console app:segment enable   beta-new-editor
 *   bin/console app:segment disable  beta-new-editor
 *   bin/console app:segment add      beta-new-editor a@x.com b@x.com   # manual include
 *   bin/console app:segment exclude  beta-new-editor c@x.com           # manual exclude
 *   bin/console app:segment remove   beta-new-editor a@x.com           # drop override
 *   bin/console app:segment delete   beta-new-editor
 *
 * A segment surfaces as the synthetic role `ROLE_SEGMENT_<KEY>`, so gate a
 * feature with is_granted('ROLE_SEGMENT_BETA_NEW_EDITOR').
 */
#[AsCommand(
    name: 'app:segment',
    description: 'List, create, configure, and populate user segments.',
)]
final class SegmentCommand extends Command
{
    private const ACTIONS = [
        'list', 'show', 'create', 'delete', 'enable', 'disable',
        'rollout', 'roles', 'add', 'exclude', 'remove',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private SegmentRepository $segments,
        private SegmentEvaluator $evaluator,
        private ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, implode(' | ', self::ACTIONS))
            ->addArgument('args', InputArgument::IS_ARRAY, 'Key, then any emails / value the action needs')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name (create)')
            ->addOption('purpose', null, InputOption::VALUE_REQUIRED, 'beta | ab_test | rollout (create)', Segment::PURPOSE_BETA)
            ->addOption('rollout', null, InputOption::VALUE_REQUIRED, 'Rollout percentage 0-100 (create)')
            ->addOption('roles', null, InputOption::VALUE_REQUIRED, 'Comma-separated included roles (create)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $action = $input->getArgument('action');
        if (!in_array($action, self::ACTIONS, true)) {
            $io->error(sprintf('Action must be one of: %s.', implode(', ', self::ACTIONS)));
            return Command::INVALID;
        }

        /** @var list<string> $args */
        $args = $input->getArgument('args');

        return match ($action) {
            'list' => $this->listSegments($io),
            'create' => $this->create($io, $input, $args),
            default => $this->withKey($io, $args, $action, $input),
        };
    }

    /**
     * @param list<string> $args
     */
    private function withKey(SymfonyStyle $io, array $args, string $action, InputInterface $input): int
    {
        $key = $args[0] ?? '';
        if ('' === $key) {
            $io->error('A segment key is required.');
            return Command::INVALID;
        }

        $segment = $this->segments->findOneByKey($key);
        if (null === $segment) {
            $io->error(sprintf('No segment with key "%s".', $key));
            return Command::FAILURE;
        }

        $rest = array_slice($args, 1);

        return match ($action) {
            'show' => $this->show($io, $segment),
            'delete' => $this->delete($io, $segment),
            'enable' => $this->setEnabled($io, $segment, true),
            'disable' => $this->setEnabled($io, $segment, false),
            'rollout' => $this->setRollout($io, $segment, $rest),
            'roles' => $this->setRoles($io, $segment, $rest),
            'add' => $this->setMembers($io, $segment, $rest, SegmentMembership::MODE_INCLUDE),
            'exclude' => $this->setMembers($io, $segment, $rest, SegmentMembership::MODE_EXCLUDE),
            'remove' => $this->removeMembers($io, $segment, $rest),
            default => Command::INVALID,
        };
    }

    private function listSegments(SymfonyStyle $io): int
    {
        $segments = $this->segments->findBy([], ['createdOn' => 'DESC']);
        if ([] === $segments) {
            $io->writeln('No segments yet. Create one with: app:segment create <key>.');
            return Command::SUCCESS;
        }

        $rows = array_map(static fn (Segment $s): array => [
            $s->getKey(),
            $s->getRole(),
            $s->isEnabled() ? 'on' : 'off',
            null === $s->getRolloutPercentage() ? '—' : $s->getRolloutPercentage() . '%',
            [] === $s->getIncludedRoles() ? '—' : implode(', ', $s->getIncludedRoles()),
        ], $segments);

        $io->table(['Key', 'Role', 'Enabled', 'Rollout', 'Roles'], $rows);
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $args
     */
    private function create(SymfonyStyle $io, InputInterface $input, array $args): int
    {
        $key = $args[0] ?? '';
        if ('' === $key) {
            $io->error('A segment key is required: app:segment create <key>.');
            return Command::INVALID;
        }
        if (null !== $this->segments->findOneByKey($key)) {
            $io->error(sprintf('A segment with key "%s" already exists.', $key));
            return Command::FAILURE;
        }

        $name = $input->getOption('name');
        $purpose = $input->getOption('purpose');
        $rolloutOpt = $input->getOption('rollout');
        $rolesOpt = $input->getOption('roles');

        $segment = new Segment();
        $segment->setKey($key);
        $segment->setName(is_string($name) && '' !== $name ? $name : $this->titleCase($key));
        $segment->setPurpose('' !== $purpose ? $purpose : Segment::PURPOSE_BETA);
        if (is_string($rolloutOpt) && '' !== $rolloutOpt) {
            $segment->setRolloutPercentage((int) $rolloutOpt);
        }
        if (is_string($rolesOpt) && '' !== $rolesOpt) {
            $segment->setIncludedRoles($this->parseRoles($rolesOpt));
        }

        $violations = $this->validator->validate($segment);
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error((string) $violation->getMessage());
            }
            return Command::INVALID;
        }

        $this->em->persist($segment);
        $this->em->flush();

        $io->success(sprintf('Created segment "%s" → %s.', $segment->getKey(), $segment->getRole()));
        return $this->show($io, $segment);
    }

    private function show(SymfonyStyle $io, Segment $segment): int
    {
        $reach = $this->evaluator->reach($segment);
        $io->definitionList(
            ['Key' => $segment->getKey()],
            ['Role' => $segment->getRole()],
            ['Name' => $segment->getName()],
            ['Purpose' => $segment->getPurpose()],
            ['Enabled' => $segment->isEnabled() ? 'yes' : 'no'],
            ['Rollout' => null === $segment->getRolloutPercentage() ? 'none' : $segment->getRolloutPercentage() . '%'],
            ['Included roles' => [] === $segment->getIncludedRoles() ? 'none' : implode(', ', $segment->getIncludedRoles())],
            ['Reach (total)' => (string) $reach['total']],
            ['  via manual' => (string) $reach['manualIncludes']],
            ['  via role' => (string) $reach['byRole']],
            ['  via rollout' => (string) $reach['byRollout']],
            ['  excluded' => (string) $reach['manualExcludes']],
        );
        return Command::SUCCESS;
    }

    private function delete(SymfonyStyle $io, Segment $segment): int
    {
        $key = $segment->getKey();
        $this->em->remove($segment);
        $this->em->flush();
        $io->success(sprintf('Deleted segment "%s".', $key));
        return Command::SUCCESS;
    }

    private function setEnabled(SymfonyStyle $io, Segment $segment, bool $enabled): int
    {
        $segment->setEnabled($enabled);
        $this->em->flush();
        $io->success(sprintf('Segment "%s" is now %s.', $segment->getKey(), $enabled ? 'enabled' : 'disabled'));
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $rest
     */
    private function setRollout(SymfonyStyle $io, Segment $segment, array $rest): int
    {
        $value = $rest[0] ?? '';
        if (1 !== preg_match('/^\d+$/', $value)) {
            $io->error('Provide a rollout percentage 0-100: app:segment rollout <key> <pct>.');
            return Command::INVALID;
        }
        $pct = (int) $value;
        if ($pct < 0 || $pct > 100) {
            $io->error('Rollout must be between 0 and 100.');
            return Command::INVALID;
        }

        $segment->setRolloutPercentage(0 === $pct ? null : $pct);
        $this->em->flush();
        $io->success(sprintf('Segment "%s" rollout set to %d%%.', $segment->getKey(), $pct));
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $rest
     */
    private function setRoles(SymfonyStyle $io, Segment $segment, array $rest): int
    {
        $roles = $this->parseRoles(implode(',', $rest));
        $segment->setIncludedRoles($roles);

        $violations = $this->validator->validate($segment);
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error((string) $violation->getMessage());
            }
            return Command::INVALID;
        }

        $this->em->flush();
        $io->success(sprintf(
            'Segment "%s" included roles set to: %s.',
            $segment->getKey(),
            [] === $roles ? 'none' : implode(', ', $roles),
        ));
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $emails
     */
    private function setMembers(SymfonyStyle $io, Segment $segment, array $emails, string $mode): int
    {
        if ([] === $emails) {
            $io->error('Provide one or more emails.');
            return Command::INVALID;
        }

        $touched = 0;
        foreach ($emails as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null === $user) {
                $io->warning(sprintf('No user found for %s — skipped.', $email));
                continue;
            }

            $membership = $this->em->getRepository(SegmentMembership::class)
                ->findOneBy(['segment' => $segment, 'user' => $user]);
            if (null === $membership) {
                $membership = new SegmentMembership();
                $membership->setSegment($segment);
                $membership->setUser($user);
                $this->em->persist($membership);
            }
            $membership->setMode($mode);
            ++$touched;
        }

        if (0 === $touched) {
            $io->error('No matching users — nothing changed.');
            return Command::FAILURE;
        }

        $this->em->flush();
        $io->success(sprintf('Set %d manual %s override(s) on "%s".', $touched, $mode, $segment->getKey()));
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $emails
     */
    private function removeMembers(SymfonyStyle $io, Segment $segment, array $emails): int
    {
        if ([] === $emails) {
            $io->error('Provide one or more emails.');
            return Command::INVALID;
        }

        $touched = 0;
        foreach ($emails as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null === $user) {
                $io->warning(sprintf('No user found for %s — skipped.', $email));
                continue;
            }
            $membership = $this->em->getRepository(SegmentMembership::class)
                ->findOneBy(['segment' => $segment, 'user' => $user]);
            if (null === $membership) {
                $io->warning(sprintf('%s has no override on "%s" — skipped.', $email, $segment->getKey()));
                continue;
            }
            $this->em->remove($membership);
            ++$touched;
        }

        if (0 === $touched) {
            $io->error('No overrides removed.');
            return Command::FAILURE;
        }

        $this->em->flush();
        $io->success(sprintf('Removed %d override(s) from "%s".', $touched, $segment->getKey()));
        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function parseRoles(string $csv): array
    {
        $roles = [];
        foreach (explode(',', $csv) as $role) {
            $role = strtoupper(trim($role));
            if ('' !== $role && !in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        return $roles;
    }

    private function titleCase(string $key): string
    {
        return ucwords(str_replace('-', ' ', $key));
    }
}
