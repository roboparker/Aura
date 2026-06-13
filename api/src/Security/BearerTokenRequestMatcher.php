<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Matches requests carrying an `Authorization: Bearer …` header, so API-token
 * traffic on the REST API is handled by a dedicated STATELESS firewall rather
 * than the cookie-backed `main` firewall. Keeping it on its own stateless
 * firewall means a token request never persists (or is required to avoid) a
 * session — which a request marked stateless on the stateful `main` firewall
 * would otherwise trip over.
 *
 * `/mcp` is unaffected: that firewall is declared earlier and matches by path,
 * so MCP Bearer requests never reach this matcher.
 */
final class BearerTokenRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        $header = $request->headers->get('Authorization');

        return null !== $header && 1 === preg_match('/^Bearer\s+/i', $header);
    }
}
