<?php

namespace App\Service;

use App\Entity\AccountExport;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

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
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendExportReady(AccountExport $export, string $plainToken): void
    {
        $recipient = $export->getRequestedBy();

        $email = (new TemplatedEmail())
            ->to($recipient->getEmail())
            ->subject('Your Madori data export is ready')
            ->htmlTemplate('emails/account_export.html.twig')
            ->textTemplate('emails/account_export.txt.twig')
            ->context([
                'recipient' => $recipient,
                'downloadUrl' => $this->mail->frontendLink('/account-exports/' . $plainToken),
                'expiresAt' => $export->getExpiresAt(),
            ]);

        $this->mail->send($email);
    }
}
