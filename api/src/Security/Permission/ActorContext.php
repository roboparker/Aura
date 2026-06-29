<?php

declare(strict_types=1);

namespace App\Security\Permission;

use App\Entity\ApiToken;
use App\Security\ApiTokenAuthenticator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Exposes the current request's actor specifics for permission decisions
 * (#space-roles). Today: the active **space-scoped API key** (an ApiToken with
 * a space), stashed on the request by {@see ApiTokenAuthenticator}. A scoped
 * key authenticates "as its creator" (so membership checks pass) but its
 * effective permissions come from its roles + space confinement, not the
 * creator — this lets the resolver/voter/extensions narrow accordingly.
 */
final class ActorContext
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function scopedKey(): ?ApiToken
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }
        $token = $request->attributes->get(ApiTokenAuthenticator::TOKEN_ATTR);

        return ($token instanceof ApiToken && $token->isScoped()) ? $token : null;
    }
}
