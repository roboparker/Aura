<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Public confirm endpoint + authenticated resend for email verification.
 * See {@see EmailVerificationService} and the ROLE_UNVERIFIED gate in
 * {@see \App\Entity\User::getRoles()}.
 */
class EmailVerificationController extends AbstractController
{
    public function __construct(
        private EmailVerificationService $verificationService,
        // Throttles the authenticated resend so a signed-in-but-unverified
        // account can't spam mail at its own inbox. Keyed per user; the
        // limit is fixed (not env-driven) like two_factor_verify.
        #[Autowire(service: 'limiter.email_verification_resend')]
        private RateLimiterFactoryInterface $resendLimiter,
    ) {
    }

    /**
     * Confirm an email address from the link. Public (the clicker only holds
     * the token, no session) — mirrors /auth/reset-password's `code` contract
     * so the PWA can show the right card.
     */
    #[Route('/verify-email', name: 'email_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $token = $data['token'] ?? null;
        $token = is_string($token) ? $token : '';

        if ('' === $token) {
            return $this->json(['error' => 'Token is required.'], 400);
        }

        $result = $this->verificationService->verify($token);
        if ('verified' === $result) {
            return $this->json(['message' => 'Your email has been verified.']);
        }

        $messages = [
            'token_invalid' => 'This verification link is invalid.',
            'token_used' => 'This verification link has already been used.',
            'token_expired' => 'This verification link has expired.',
        ];

        return $this->json([
            'error' => $messages[$result] ?? 'This verification link is invalid.',
            'code' => $result,
        ], 400);
    }

    /**
     * Re-send the confirmation email to the signed-in account. Reachable by a
     * ROLE_UNVERIFIED session (see security.yaml) so a user boxed into the
     * gate can request a fresh link.
     */
    #[Route('/me/verification/resend', name: 'email_verify_resend', methods: ['POST'])]
    public function resend(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        // Already-verified is a no-op success so a stale client tab can't
        // trigger pointless mail.
        if ($user->isEmailVerified()) {
            return $this->json(['message' => 'Your email is already verified.']);
        }

        $limit = $this->resendLimiter->create('user-' . $user->getUserIdentifier())->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->json(
                [
                    'error' => 'Too many verification emails requested. Please try again later.',
                    'retryAfter' => $retryAfter,
                ],
                429,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $this->verificationService->send($user);

        return $this->json(['message' => 'A new verification email is on its way.']);
    }
}
