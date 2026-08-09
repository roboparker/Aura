<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Two account states are checked at sign-in, and they behave oppositely on
 * purpose:
 *
 * - **Deactivated** — a pause. Coming back and authenticating clears it, which
 *   is the whole intent of the feature.
 * - **Deleted (grace period)** — refused. Deletion is meant to be undone
 *   deliberately, through the link emailed to the account holder, not as a side
 *   effect of typing a password. Auto-restoring here would also mean anyone who
 *   has the password can silently cancel a deletion, and would leave "deleted"
 *   and "deactivated" indistinguishable in practice.
 */
final class UserChecker implements UserCheckerInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // No pre-auth gate — credentials are validated first, so a wrong
        // password can't be used to probe whether an account is pending
        // deletion.
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }
        if ($user->isDeleted()) {
            throw new AccountDeletionPendingException();
        }
        if ($user->isDeactivated()) {
            $user->setDeactivatedAt(null);
            $this->em->flush();
        }
    }
}
