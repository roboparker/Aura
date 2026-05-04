<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Rolls up pending in-app notifications for users on the digest cadence
 * (`hourly` or `daily`) and ships them as a single grouped email per run.
 *
 * Pairs with App\Command\DispatchTaskRemindersCommand: the realtime path
 * there sends per-notification emails and explicitly skips users on a
 * digest frequency, so this command owns their email delivery instead.
 *
 * Idempotency: rows that have already been included in a digest are
 * stamped with `digestedAt`, so reruns inside the same window won't
 * resend. Recommended cron cadence (also documented in deployment.md):
 *   - `--period=hourly` at minute 55
 *   - `--period=daily`  at 08:00 UTC
 */
#[AsCommand(
    name: 'app:notifications:dispatch-digest',
    description: 'Group pending notifications into a single digest email per recipient.',
)]
final class DispatchNotificationDigestCommand extends Command
{
    private const ALLOWED_PERIODS = ['hourly', 'daily'];

    public function __construct(
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
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
        if (!in_array($period, self::ALLOWED_PERIODS, true)) {
            $io->error(sprintf(
                'Option --period must be one of: %s.',
                implode(', ', self::ALLOWED_PERIODS),
            ));
            return Command::INVALID;
        }

        $now = new \DateTimeImmutable();
        $emailsSent = 0;
        $rowsDigested = 0;

        foreach ($this->findUsersByFrequency($period) as $user) {
            $prefs = $user->getPreferences();
            // Honour the per-user opt-out even though they're on a digest
            // frequency — the toggle is the master switch for any email
            // delivery, regardless of cadence.
            if (false === ($prefs['emailNotificationsEnabled'] ?? true)) {
                continue;
            }

            $pending = $this->notifications->findPendingDigestForRecipient($user);
            if ([] === $pending) {
                continue;
            }

            if ($this->sendDigestEmail($user, $pending, $period, $io)) {
                ++$emailsSent;
            }

            // Stamp regardless of mailer success — if the SMTP call fails
            // we've already warned, and re-sending the same digest on the
            // next cron run would just spam the recipient. The in-app
            // notifications stay accessible through the bell either way.
            foreach ($pending as $row) {
                $row->setDigestedAt($now);
                ++$rowsDigested;
            }
            $this->em->flush();
        }

        $io->success(sprintf(
            'Sent %d %s digest(s) covering %d notification(s).',
            $emailsSent,
            $period,
            $rowsDigested,
        ));
        return Command::SUCCESS;
    }

    /**
     * Returns every user whose `notificationFrequency` preference matches
     * the requested period. We load all users and filter in PHP because
     * preferences live in a JSON column without a generated index — fine
     * for the current scale, and sidesteps Postgres-specific DQL.
     *
     * @return User[]
     */
    private function findUsersByFrequency(string $period): array
    {
        $all = $this->em->getRepository(User::class)->findAll();
        return array_values(array_filter(
            $all,
            static fn (User $u) => ($u->getPreferences()['notificationFrequency'] ?? 'realtime') === $period,
        ));
    }

    /**
     * @param Notification[] $pending
     */
    private function sendDigestEmail(
        User $recipient,
        array $pending,
        string $period,
        SymfonyStyle $io,
    ): bool {
        $count = count($pending);
        $subject = sprintf(
            '%s digest: %d notification%s waiting',
            ucfirst($period),
            $count,
            1 === $count ? '' : 's',
        );

        $email = (new TemplatedEmail())
            ->from($this->mailerFrom ?: 'no-reply@aura.test')
            ->to($recipient->getEmail())
            ->subject($subject)
            ->htmlTemplate('emails/notification_digest.html.twig')
            ->textTemplate('emails/notification_digest.txt.twig')
            ->context([
                'recipient' => $recipient,
                'notifications' => $pending,
                'period' => $period,
                'tasksUrl' => rtrim($this->frontendUrl, '/') . '/tasks',
            ]);

        try {
            $this->mailer->send($email);
            return true;
        } catch (TransportExceptionInterface $e) {
            $io->warning(sprintf(
                'Failed to send %s digest to %s: %s',
                $period,
                $recipient->getEmail(),
                $e->getMessage(),
            ));
            return false;
        }
    }
}
