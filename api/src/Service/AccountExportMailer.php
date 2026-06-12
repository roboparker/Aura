<?php

namespace App\Service;

use App\Entity\AccountExport;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Sends the "your account export is ready" email once the worker has built
 * the archive. The link carries the plaintext download token (only its
 * sha256 lands in the database, mirroring the password-reset model) and
 * points at the PWA's /account-exports/{token} landing page, which requires
 * the requester to be signed in before the bytes are served.
 */
final class AccountExportMailer
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    public function sendExportReady(AccountExport $export, string $plainToken): void
    {
        $from = (null !== $this->mailerFrom && '' !== $this->mailerFrom) ? $this->mailerFrom : 'no-reply@madori.test';
        $recipient = $export->getRequestedBy();

        $email = (new TemplatedEmail())
            ->from($from)
            ->to($recipient->getEmail())
            ->subject('Your Madori data export is ready')
            ->htmlTemplate('emails/account_export.html.twig')
            ->textTemplate('emails/account_export.txt.twig')
            ->context([
                'recipient' => $recipient,
                'downloadUrl' => rtrim($this->frontendUrl, '/') . '/account-exports/' . $plainToken,
                'expiresAt' => $export->getExpiresAt(),
            ]);

        $this->mailer->send($email);
    }
}
