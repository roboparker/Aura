<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Reactivates a soft-deactivated account on a successful sign-in. Deactivation
 * (Settings → Danger zone) signs the user out everywhere and flags the
 * account; coming back and authenticating clears the flag.
 */
final class UserChecker implements UserCheckerInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // No pre-auth gate — credentials are validated first.
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }
        if ($user->isDeactivated()) {
            $user->setDeactivatedAt(null);
            $this->em->flush();
        }
    }
}
