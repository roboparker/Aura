<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\TwoFactorRecoveryMailer;
use App\Service\TwoFactorRecoveryState;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\BackupCodeEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Fires when SchebTwoFactorBundle's backup-code listener has accepted a
 * recovery code at /auth/2fa-check. Two side effects:
 *
 *  1. Flag the session through {@see TwoFactorRecoveryState} so the PWA's
 *     next /api/me poll surfaces `twoFactor.recoveryPending: true` and
 *     mounts the recovery interstitial. The flag also tells
 *     {@see \App\Service\SensitiveActionVerifier} to accept the user's
 *     password as step-up (instead of demanding a TOTP they don't have)
 *     for the disable / re-enroll calls coming up.
 *
 *  2. Send a "recovery code used" notification email so an account whose
 *     code was stolen gets an out-of-band alert before the attacker can
 *     act on it.
 *
 * Mail failures are logged and swallowed — the user has already
 * authenticated; a flaky SMTP must not pin them out of their account.
 */
final class TwoFactorRecoveryListener implements EventSubscriberInterface
{
    public function __construct(
        private TwoFactorRecoveryState $state,
        private TwoFactorRecoveryMailer $mailer,
        #[Autowire(service: 'logger')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            BackupCodeEvents::VALID => 'onBackupCodeAccepted',
        ];
    }

    public function onBackupCodeAccepted(TwoFactorCodeEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->state->markPending();

        try {
            $this->mailer->sendBackupCodeUsed($user);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Failed to send 2FA backup-code notification email.', [
                'userId' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
