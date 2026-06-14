<?php

namespace App\Service;

use App\Entity\SpaceExport;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Sends the "your space export is ready" email once the worker has built
 * the archive. The link carries the plaintext download token (only its
 * sha256 lands in the database, mirroring the password-reset model) and
 * points at the PWA's /exports/{token} landing page, which requires the
 * requester to be signed in before the bytes are served.
 */
final class SpaceExportMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendExportReady(SpaceExport $export, string $plainToken): void
    {
        $recipient = $export->getRequestedBy();

        $email = (new TemplatedEmail())
            ->to($recipient->getEmail())
            ->subject(sprintf('Your export of "%s" is ready', $export->getSpace()->getName()))
            ->htmlTemplate('emails/space_export.html.twig')
            ->textTemplate('emails/space_export.txt.twig')
            ->context([
                'recipient' => $recipient,
                'space' => $export->getSpace(),
                'downloadUrl' => $this->mail->frontendLink('/exports/' . $plainToken),
                'expiresAt' => $export->getExpiresAt(),
            ]);

        $this->mail->send($email);
    }
}
