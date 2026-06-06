<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stamps `User.totpLastVerifiedAt` when a user completes the 2FA challenge
 * (TOTP or backup code), so the Settings security panel can show
 * "last verified …". Listens on the COMPLETE event — fired once the whole
 * two-factor process succeeds and the user is fully authenticated.
 */
final class TwoFactorVerifiedStamper implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::COMPLETE => 'onComplete',
        ];
    }

    public function onComplete(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->setTotpLastVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
