<?php

namespace App\Service;

use App\Entity\User;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Sends the "your account is ready" email when a waitlisted user is promoted
 * to a full account (an admin opening signups via the waitlist toggle).
 *
 * Mirrors InviteMailer's wiring (MAILER_FROM fallback, APP_FRONTEND_URL for
 * the sign-in link). Sending is best-effort at the call site
 * ({@see WaitlistPromoter}) — a dead SMTP server must not roll back the
 * promotion that already happened in the database.
 */
final class WaitlistAccessMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendAccessGranted(User $user): void
    {
        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Your Madori account is ready')
            ->htmlTemplate('emails/waitlist_access.html.twig')
            ->textTemplate('emails/waitlist_access.txt.twig')
            ->context([
                'recipient' => $user,
                'signinUrl' => $this->mail->frontendLink('/signin'),
            ]);

        $this->mail->send($email);
    }
}
