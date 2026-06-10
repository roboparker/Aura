<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * JSON failure responses for the json_login authenticator on the `main`
 * firewall.
 *
 * Ordinary bad-credential failures keep the authenticator's stock shape —
 * 401 with {error} — which the PWA's AuthContext already reads. The one
 * addition is the login_throttling listener's
 * {@see TooManyLoginAttemptsAuthenticationException}
 * (thrown when the `app.login_rate_limiter` buckets are empty — see
 * services.yaml): that becomes a structured 429 with both a Retry-After
 * header and a `retryAfter` body field, mirroring the 2FA challenge's
 * throttle response in {@see TwoFactorJsonHandler::onAuthenticationFailure()}
 * so the client can drive a countdown without parsing the message.
 */
final class JsonLoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            // The exception only carries minute granularity (its
            // `%minutes%` message parameter, ceil'd from the limiter's
            // retry-after) — surface the same number in seconds.
            $minutes = $exception->getMessageData()['%minutes%'] ?? null;
            $retryAfterSeconds = is_numeric($minutes) ? max(60, 60 * (int) $minutes) : 60;

            return new JsonResponse(
                [
                    'error' => sprintf(
                        'Too many failed login attempts. Try again in %d minute(s).',
                        intdiv($retryAfterSeconds, 60),
                    ),
                    'retryAfter' => $retryAfterSeconds,
                ],
                429,
                ['Retry-After' => (string) $retryAfterSeconds],
            );
        }

        return new JsonResponse(['error' => $exception->getMessageKey()], 401);
    }
}
