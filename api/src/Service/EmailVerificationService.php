<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Mints + emails single-use email-confirmation links, and applies them.
 *
 * Token handling mirrors {@see \App\Controller\PasswordController} /
 * {@see \App\Entity\PasswordResetToken}: a random 32-byte token is emailed
 * in plaintext while only its sha256 hash is persisted.
 */
final class EmailVerificationService
{
    private const TOKEN_TTL_HOURS = 24;

    public function __construct(
        private EntityManagerInterface $em,
        private EmailVerificationTokenRepository $tokenRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    /**
     * Issue a fresh token (superseding any outstanding one) and email the
     * confirmation link. Best-effort delivery: a dead transport is logged
     * but never undoes the signup that already landed — the user can hit
     * "resend" from the gate.
     */
    public function send(User $user): void
    {
        $this->tokenRepository->invalidateAllForUser($user);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d hours', self::TOKEN_TTL_HOURS));

        $this->em->persist(new EmailVerificationToken($user, $tokenHash, $expiresAt));
        $this->em->flush();

        try {
            $this->sendEmail($user, $plainToken);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Failed to send email-verification message to {email}: {error}', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Consume a plaintext token: mark the owning user verified and the token
     * used. Returns a machine code the caller maps to a response:
     * 'verified' (success or already-verified idempotent replay),
     * 'token_invalid', 'token_used', or 'token_expired'.
     */
    public function verify(string $plainToken): string
    {
        $token = $this->tokenRepository->findByTokenHash(hash('sha256', $plainToken));

        if (null === $token) {
            return 'token_invalid';
        }
        if (null !== $token->getUsedAt()) {
            // A used token whose owner is verified is a harmless double-click
            // on the link — report success rather than an error.
            return $token->getUser()->isEmailVerified() ? 'verified' : 'token_used';
        }
        if (!$token->isValid()) {
            return 'token_expired';
        }

        $token->getUser()->setEmailVerified(true);
        $token->markUsed();
        $this->em->flush();

        return 'verified';
    }

    private function sendEmail(User $user, string $plainToken): void
    {
        $verifyUrl = sprintf('%s/verify-email?token=%s', rtrim($this->frontendUrl, '/'), $plainToken);
        $from = (null !== $this->mailerFrom && '' !== $this->mailerFrom) ? $this->mailerFrom : 'no-reply@madori.test';

        $email = (new Email())
            ->from($from)
            ->to($user->getEmail())
            ->subject('Confirm your Madori email')
            ->text(sprintf(
                "Hi,\n\nThanks for signing up for Madori. Confirm your email address by clicking the link below:\n\n%s\n\nThis link expires in %d hours. If you didn't create a Madori account, you can safely ignore this email.\n\n— Madori",
                $verifyUrl,
                self::TOKEN_TTL_HOURS,
            ))
            ->html(sprintf(
                '<p>Hi,</p><p>Thanks for signing up for Madori. Confirm your email address by clicking the link below:</p><p><a href="%1$s">%1$s</a></p><p>This link expires in %2$d hours. If you didn\'t create a Madori account, you can safely ignore this email.</p><p>— Madori</p>',
                htmlspecialchars($verifyUrl),
                self::TOKEN_TTL_HOURS,
            ));

        $this->mailer->send($email);
    }
}
