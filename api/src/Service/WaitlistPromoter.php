<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Promotes every waitlisted account to a full user in one pass — invoked when
 * an admin turns waitlist mode off (opening signups).
 *
 * The flag flip + flush happens first as a single unit so the DB state is
 * never half-promoted; emails go out afterwards as best-effort (a dead SMTP
 * server logs a warning but can't undo the promotion that already landed).
 */
final class WaitlistPromoter
{
    public function __construct(
        private EntityManagerInterface $em,
        private WaitlistAccessMailer $mailer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int the number of accounts promoted
     */
    public function promoteAll(): int
    {
        $waitlisted = $this->em->getRepository(User::class)->findBy(['waitlisted' => true]);
        if ([] === $waitlisted) {
            return 0;
        }

        foreach ($waitlisted as $user) {
            $user->setWaitlisted(false);
        }
        $this->em->flush();

        foreach ($waitlisted as $user) {
            $this->sendAccess($user);
        }

        return count($waitlisted);
    }

    /**
     * Promote a single waitlisted account (the admin's per-user "grant access"
     * button). No-op if the user isn't actually waitlisted, so a double-click
     * or a stale list doesn't re-send the email.
     */
    public function promoteOne(User $user): void
    {
        if (!$user->isWaitlisted()) {
            return;
        }

        $user->setWaitlisted(false);
        $this->em->flush();
        $this->sendAccess($user);
    }

    private function sendAccess(User $user): void
    {
        try {
            $this->mailer->sendAccessGranted($user);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Failed to send waitlist access email to {email}: {error}', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
