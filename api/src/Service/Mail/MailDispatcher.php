<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Shared collaborator for the transactional mailers. It owns the three things
 * every mailer needs — the MailerInterface, the frontend base URL, and the
 * MAILER_FROM fallback — so the concrete mailers compose it (inject + call)
 * rather than inheriting a base class.
 *
 * {@see send()} stamps the From address when the message doesn't already carry
 * one, so callers build their {@see Email}/TemplatedEmail without repeating the
 * fallback; {@see frontendLink()} builds absolute links into the PWA.
 */
final class MailDispatcher
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private readonly string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private readonly ?string $mailerFrom = null,
    ) {
    }

    /**
     * Sends $email, defaulting its From to the configured sender (or the dev
     * fallback) when the caller hasn't set one.
     */
    public function send(Email $email): void
    {
        if ([] === $email->getFrom()) {
            $email->from($this->senderAddress());
        }

        $this->mailer->send($email);
    }

    /**
     * Absolute frontend URL for $path (a leading slash is expected), with any
     * trailing slash on the configured base trimmed so the result is well-formed.
     */
    public function frontendLink(string $path = ''): string
    {
        return rtrim($this->frontendUrl, '/') . $path;
    }

    /**
     * The From address, falling back to the dev default when MAILER_FROM is
     * unset or empty.
     */
    private function senderAddress(): string
    {
        return (null !== $this->mailerFrom && '' !== $this->mailerFrom)
            ? $this->mailerFrom
            : 'no-reply@madori.test';
    }
}
