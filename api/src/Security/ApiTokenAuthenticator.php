<?php

namespace App\Security;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates `Authorization: Bearer madori_pat_…` requests on BOTH the
 * stateless `mcp` firewall and the main REST firewall, so a token can drive
 * the same API surface its owner can. The plaintext is sha256-hashed and
 * looked up against {@see ApiToken::tokenHash}; on a hit we attach the owning
 * {@see User} to the security token and stamp `lastUsedAt`.
 *
 * What the token may actually do is decided downstream by its
 * {@see \App\Security\Access\AccessPolicy} (REST: AccessPolicyListener; MCP:
 * McpToolPolicy). The matched ApiToken is stashed on the request so both
 * surfaces can read the policy without re-loading it.
 *
 * `supports()` only returns true when the Bearer header is present, and the
 * request is marked stateless, so the cookie / json_login / 2FA flow on the
 * main firewall is never affected by tokens.
 *
 * Failure responses are JSON rather than the default HTML error page.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public const TOKEN_ATTR = '_madori_api_token';

    public function __construct(
        private ApiTokenRepository $tokens,
        private EntityManagerInterface $em,
    ) {
    }

    public function supports(Request $request): bool
    {
        // Only kick in when the header is actually present. Returning false
        // lets the firewall surface a clean 401 via onAuthenticationFailure
        // when the access_control rule then rejects the unauthenticated
        // request, instead of trying to parse a missing header.
        return null !== $this->extractBearer($request);
    }

    public function authenticate(Request $request): Passport
    {
        $bearer = $this->extractBearer($request);
        if (null === $bearer || !str_starts_with($bearer, ApiToken::PLAINTEXT_PREFIX)) {
            throw new CustomUserMessageAuthenticationException('Invalid API token.');
        }

        $token = $this->tokens->findByTokenHash(hash('sha256', $bearer));
        if (null === $token) {
            throw new CustomUserMessageAuthenticationException('Invalid API token.');
        }
        if ($token->isExpired()) {
            throw new CustomUserMessageAuthenticationException('API token has expired.');
        }
        $user = $token->getUser();
        if (!$user instanceof User) {
            throw new CustomUserMessageAuthenticationException('API token has no user.');
        }

        // Stamp last-used eagerly. We don't gate this on tool success — the
        // bearer was demonstrably valid right now, and the field is an
        // observability hint, not a security control.
        $token->touch();
        $this->em->flush();

        // Stash the matched ApiToken on the request so downstream code (the
        // REST AccessPolicyListener + the MCP controller) can read the
        // token's policy without re-loading from the DB.
        $request->attributes->set(self::TOKEN_ATTR, $token);
        // Bearer auth is stateless even on the stateful main firewall — don't
        // persist a session for an API-token request.
        $request->attributes->set('_stateless', true);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn () => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $messageKey = $exception->getMessageKey();
        return new JsonResponse(
            ['error' => '' !== $messageKey ? $messageKey : 'Authentication failed.'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="madori"'],
        );
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (null === $header) {
            return null;
        }
        if (1 !== preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }
        return $matches[1];
    }
}
