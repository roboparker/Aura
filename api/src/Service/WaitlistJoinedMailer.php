<?php

namespace App\Service;

use App\Entity\User;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

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
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendJoinedConfirmation(User $user): void
    {
        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject("You're on the Madori waitlist")
            ->htmlTemplate('emails/waitlist_joined.html.twig')
            ->textTemplate('emails/waitlist_joined.txt.twig')
            ->context([
                'recipient' => $user,
            ]);

        $this->mail->send($email);
    }
}
