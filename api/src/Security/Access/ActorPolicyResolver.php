<?php

declare(strict_types=1);

namespace App\Security\Access;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Security\ApiTokenAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * Resolves the {@see AccessPolicy} that constrains the CURRENT request's
 * actor, or null when the actor is unconstrained (a normal interactive
 * session, or an API token with no policy).
 *
 * This is the single place that knows which actors carry a policy, so the
 * enforcement listener + the collection item-scope stay actor-agnostic:
 *
 *  - admin impersonation (SwitchUserToken) → the target user's consent,
 *  - API token auth → the token's own policy (null = unrestricted),
 *  - future AI agents → their policy (same hook).
 */
final class ActorPolicyResolver
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public function resolve(): ?AccessPolicy
    {
        $token = $this->security->getToken();
        if ($token instanceof SwitchUserToken) {
            $target = $token->getUser();

            return $target instanceof User ? $target->getImpersonationPolicy() : null;
        }

        // API-token actors: the authenticator stashes the matched ApiToken on
        // the request. A token with no policy is unrestricted (acts as owner).
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request) {
            $apiToken = $request->attributes->get(ApiTokenAuthenticator::TOKEN_ATTR);
            if ($apiToken instanceof ApiToken) {
                return $apiToken->toAccessPolicy();
            }
        }

        return null;
    }
}
