<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Resolves the authenticated {@see User} or rejects with a 401. The
 * "stamp the current user as owner/author" creation processors
 * ({@see \App\State\TaskOwnerProcessor}, {@see \App\State\CommentAuthorProcessor}, …)
 * compose this resolver instead of each repeating the getUser() + instanceof
 * guard, so the unauthenticated-request behaviour is defined in one place.
 */
final class AuthenticatedUserResolver
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * @param string $action fills "You must be authenticated to <action>."
     *                       (e.g. "create a task")
     */
    public function requireUser(string $action): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', sprintf('You must be authenticated to %s.', $action));
        }

        return $user;
    }
}
