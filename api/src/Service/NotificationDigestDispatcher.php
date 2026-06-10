<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Rolls up pending in-app notifications for users on the digest cadence
 * (`hourly` or `daily`) and ships them as a single grouped email per run.
 *
 * Pairs with App\Service\TaskReminderDispatcher: the realtime path there
 * sends per-notification emails and explicitly skips users on a digest
 * frequency, so this dispatcher owns their email delivery instead.
 *
 * Idempotency: rows that have already been included in a digest are
 * stamped with `digestedAt`, so reruns inside the same window won't
 * resend. Shared by App\Command\DispatchNotificationDigestCommand
 * (manual / one-off runs) and
 * App\MessageHandler\DispatchNotificationDigestHandler (the scheduler's
 * recurring passes — hourly at minute 55, daily at 08:00 UTC).
 */
final class NotificationDigestDispatcher
{
    public const ALLOWED_PERIODS = ['hourly', 'daily'];

    public function __construct(
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when $period isn't a digest cadence
     */
    public function dispatch(string $period): NotificationDigestResult
    {
        if (!in_array($period, self::ALLOWED_PERIODS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Period must be one of: %s.',
                implode(', ', self::ALLOWED_PERIODS),
            ));
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

            if ($this->sendDigestEmail($user, $pending, $period)) {
                ++$emailsSent;
            }

            // Stamp regardless of mailer success — if the SMTP call fails
            // we've already warned, and re-sending the same digest on the
            // next run would just spam the recipient. The in-app
            // notifications stay accessible through the bell either way.
            foreach ($pending as $row) {
                $row->setDigestedAt($now);
                ++$rowsDigested;
            }
            $this->em->flush();
        }

        return new NotificationDigestResult($emailsSent, $rowsDigested);
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
            // notificationFrequency is the canonical cadence (the Settings UI
            // keeps emailDigest.mode in sync with it).
            static fn (User $u) => ($u->getPreferences()['notificationFrequency'] ?? 'realtime') === $period,
        ));
    }

    /**
     * @param Notification[] $pending
     */
    private function sendDigestEmail(User $recipient, array $pending, string $period): bool
    {
        $count = count($pending);
        $subject = sprintf(
            '%s digest: %d notification%s waiting',
            ucfirst($period),
            $count,
            1 === $count ? '' : 's',
        );

        $email = (new TemplatedEmail())
            ->from((null !== $this->mailerFrom && '' !== $this->mailerFrom) ? $this->mailerFrom : 'no-reply@aura.test')
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
            $this->logger->warning(sprintf(
                'Failed to send %s digest to %s: %s',
                $period,
                $recipient->getEmail(),
                $e->getMessage(),
            ));
            return false;
        }
    }
}
