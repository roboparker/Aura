<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Security\TooManyTwoFactorAttemptsException;
use App\Service\UserPayloadSerializer;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * JSON handlers wired into the Scheb two-factor firewall so the SPA gets
 * structured responses instead of HTML redirects.
 *
 * Three flows hit this class:
 *  - authenticationRequired(): /auth/login finished password auth and the
 *    user has 2FA enabled — return 401 with {requiresTwoFactor: true}
 *  - onAuthenticationSuccess(): /auth/2fa-check accepted a code — return
 *    the same user payload as /auth/login does on success
 *  - onAuthenticationFailure(): wrong/expired code — 401 with an error
 *    string (no detail beyond "invalid" so we don't leak which path the
 *    user chose, totp vs backup)
 */
final class TwoFactorJsonHandler implements
    AuthenticationRequiredHandlerInterface,
    AuthenticationSuccessHandlerInterface,
    AuthenticationFailureHandlerInterface
{
    public function __construct(private UserPayloadSerializer $userPayloadSerializer)
    {
    }

    public function onAuthenticationRequired(Request $request, TokenInterface $token): Response
    {
        $providers = $token instanceof TwoFactorTokenInterface
            ? $token->getTwoFactorProviders()
            : ['totp'];

        return new JsonResponse([
            'requiresTwoFactor' => true,
            'providers' => $providers,
        ], 401);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentication state is invalid.'], 500);
        }

        return new JsonResponse($this->userPayloadSerializer->serialize($user));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Rate-limit hit: thrown from
        // {@see \App\EventSubscriber\TwoFactorChallengeRateLimiter} when
        // the per-user bucket is empty. Surface as a structured 429
        // with both header and body retry-after so the PWA can drive a
        // countdown without parsing the message string.
        $rateLimit = $exception instanceof TooManyTwoFactorAttemptsException
            ? $exception
            : ($exception->getPrevious() instanceof TooManyTwoFactorAttemptsException
                ? $exception->getPrevious()
                : null);
        if (null !== $rateLimit) {
            return new JsonResponse(
                [
                    'error' => sprintf(
                        'Too many attempts. Try again in %d seconds.',
                        $rateLimit->retryAfterSeconds,
                    ),
                    'retryAfter' => $rateLimit->retryAfterSeconds,
                ],
                429,
                ['Retry-After' => (string) $rateLimit->retryAfterSeconds],
            );
        }

        return new JsonResponse([
            'error' => 'Invalid authentication code.',
        ], 401);
    }
}
