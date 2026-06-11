<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Sends the "you're on the waitlist" confirmation email at signup time while
 * waitlist mode is on. Purely informational — it sets the expectation that we
 * email when access opens and closes the loop on a signup that otherwise gets
 * only the in-app confirmation screen (#404).
 *
 * Reads as a pair with {@see WaitlistAccessMailer} (same MAILER_FROM fallback);
 * carries no token and nothing actionable. Sending is best-effort at the call
 * site ({@see \App\State\UserPasswordHasherProcessor}) — a dead SMTP server
 * must not roll back the signup that already landed in the database.
 */
final class WaitlistJoinedMailer
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    public function sendJoinedConfirmation(User $user): void
    {
        $from = (null !== $this->mailerFrom && '' !== $this->mailerFrom) ? $this->mailerFrom : 'no-reply@aura.test';

        $email = (new TemplatedEmail())
            ->from($from)
            ->to($user->getEmail())
            ->subject("You're on the Aura waitlist")
            ->htmlTemplate('emails/waitlist_joined.html.twig')
            ->textTemplate('emails/waitlist_joined.txt.twig')
            ->context([
                'recipient' => $user,
            ]);

        $this->mailer->send($email);
    }
}
