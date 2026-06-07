<?php

namespace App\Controller;

use App\Service\UserPayloadSerializer;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

class AuthController extends AbstractController
{
    public function __construct(
        private Security $security,
        private UserPayloadSerializer $userPayloadSerializer,
    ) {
    }

    #[Route('/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(#[CurrentUser] ?User $user): JsonResponse
    {
        // After json_login succeeds Scheb's AuthenticationTokenListener
        // wraps the token in a TwoFactorToken when the user has 2FA on.
        // The json_login firewall's default success path still routes
        // here, so we surface the "challenge needed" response ourselves
        // instead of leaking the user payload pre-2FA.
        $token = $this->security->getToken();
        if ($token instanceof TwoFactorTokenInterface) {
            return $this->json([
                'requiresTwoFactor' => true,
                'providers' => $token->getTwoFactorProviders(),
            ], 401);
        }

        if (null === $user) {
            return $this->json([
                'error' => 'Invalid credentials.',
            ], 401);
        }

        return $this->json($this->userPayloadSerializer->serialize($user));
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        return $this->json($this->userPayloadSerializer->serialize($user));
    }
}
