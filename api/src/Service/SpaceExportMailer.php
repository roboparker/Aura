<?php

namespace App\Service;

use App\Entity\SpaceExport;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

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
        private MailerInterface $mailer,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    public function sendExportReady(SpaceExport $export, string $plainToken): void
    {
        $from = (null !== $this->mailerFrom && '' !== $this->mailerFrom) ? $this->mailerFrom : 'no-reply@aura.test';
        $recipient = $export->getRequestedBy();

        $email = (new TemplatedEmail())
            ->from($from)
            ->to($recipient->getEmail())
            ->subject(sprintf('Your export of "%s" is ready', $export->getSpace()->getName()))
            ->htmlTemplate('emails/space_export.html.twig')
            ->textTemplate('emails/space_export.txt.twig')
            ->context([
                'recipient' => $recipient,
                'space' => $export->getSpace(),
                'downloadUrl' => rtrim($this->frontendUrl, '/') . '/exports/' . $plainToken,
                'expiresAt' => $export->getExpiresAt(),
            ]);

        $this->mailer->send($email);
    }
}
