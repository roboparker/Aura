<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

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
        private MailerInterface $mailer,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    public function sendAccessGranted(User $user): void
    {
        $from = (null !== $this->mailerFrom && '' !== $this->mailerFrom) ? $this->mailerFrom : 'no-reply@madori.test';

        $email = (new TemplatedEmail())
            ->from($from)
            ->to($user->getEmail())
            ->subject('Your Madori account is ready')
            ->htmlTemplate('emails/waitlist_access.html.twig')
            ->textTemplate('emails/waitlist_access.txt.twig')
            ->context([
                'recipient' => $user,
                'signinUrl' => rtrim($this->frontendUrl, '/') . '/signin',
            ]);

        $this->mailer->send($email);
    }
}
