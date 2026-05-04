<?php

namespace App\Service;

use App\Entity\User;
use Gedmo\Tool\ActorProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Provides the actor for Gedmo's Loggable extension as a stable IRI
 * (`/users/{uuid}`) instead of the User's `getUserIdentifier()`
 * (= email).
 *
 * Why: emails change (see the email-change flow), but the User row's
 * UUID never does. Storing the IRI in `ext_log_entries.username`
 * makes audit history survive email rotations and lets the activity
 * controllers resolve back to the User entity by ID.
 *
 * Returning a string from `getActor()` is the documented
 * fast-path on `ActorProviderInterface` — the LoggableListener
 * accepts strings verbatim and skips the `getUserIdentifier()`
 * call. Anonymous requests return null, and Gedmo writes "" for
 * those (fine for fixtures and maintenance commands).
 */
final class IriActorProvider implements ActorProviderInterface
{
    public function __construct(private Security $security)
    {
    }

    public function getActor(): ?string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            return null;
        }
        return '/users/' . $user->getId();
    }
}
