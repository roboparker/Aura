<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\TwoFactorSecretCipher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Decrypts the TOTP secret into a transient cache field on User after
 * Doctrine hydration. Without this, Scheb would call
 * getTotpAuthenticationConfiguration() on a fresh entity and find a null
 * secret.
 *
 * Auto-registered via #[AsEntityListener] — no service config needed.
 */
#[AsEntityListener(event: Events::postLoad, entity: User::class)]
final class UserTotpCipherInjector
{
    public function __construct(private TwoFactorSecretCipher $cipher)
    {
    }

    public function postLoad(User $user): void
    {
        $encrypted = $user->getTotpSecretEncrypted();
        if (null === $encrypted) {
            return;
        }
        $user->setTotpSecretCache($this->cipher->decrypt($encrypted));
    }
}
