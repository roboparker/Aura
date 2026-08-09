<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AccountStatusException;

/**
 * Sign-in refused because the account is inside its deletion grace period.
 *
 * Thrown from {@see UserChecker::checkPostAuth()} — i.e. only after the
 * credentials have already been proven correct, so it can't be used to probe
 * which addresses have a deletion pending.
 *
 * The message is deliberately actionable rather than generic: the person is the
 * account holder (they just authenticated) and the one thing they need to know
 * is that the restore link is in their inbox.
 */
final class AccountDeletionPendingException extends AccountStatusException
{
    public function getMessageKey(): string
    {
        return 'This account is scheduled for deletion. Use the restore link we emailed you to bring it back.';
    }
}
