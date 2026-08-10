<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AccountStatusException;

/**
 * Sign-in refused because the account is an AI agent (#827), not a person.
 *
 * An agent authenticates only as a Bearer {@see \App\Entity\ApiToken}; it has
 * no password and no session. Thrown from {@see UserChecker::checkPostAuth()},
 * so like {@see AccountDeletionPendingException} it is only reachable once
 * authentication has otherwise succeeded and cannot be used to probe which
 * addresses belong to agents.
 *
 * The message is generic on purpose. Unlike the deletion case there is no
 * account holder on the other end to give advice to — whoever sees this is
 * someone who should not have got this far.
 */
final class AgentSignInDeniedException extends AccountStatusException
{
    public function getMessageKey(): string
    {
        return 'This account cannot sign in.';
    }
}
