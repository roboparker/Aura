<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminUserCreator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

/**
 * Platform-admin account provisioning (#admin-user-create):
 * `POST /admin/users` creates an account that can sign in immediately —
 * clearing the email-confirmation and waitlist gates that normal signup
 * imposes — and returns the generated password **once** (never stored in
 * plaintext, same one-shot shape as the API-token reveal).
 *
 * This is an account-creation vector, so it is ROLE_ADMIN-only (also gated in
 * security.yaml) and every creation is logged with the acting admin.
 */
class AdminUserController extends AbstractController
{
    public function __construct(
        private AdminUserCreator $creator,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/users', name: 'admin_user_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = $request->toArray();
        $email = $this->stringField($payload, 'email');
        $givenName = $this->stringField($payload, 'givenName');
        $familyName = $this->stringField($payload, 'familyName');

        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'A valid email is required.'], 422);
        }
        if ('' === $givenName || '' === $familyName) {
            return $this->json(['error' => 'First and last name are required.'], 422);
        }

        // `skipWaitlist` defaults to true — an admin-created account is meant to
        // be usable straight away; pass false to leave it waiting.
        $skipWaitlist = !array_key_exists('skipWaitlist', $payload) || true === $payload['skipWaitlist'];

        $spaceName = null;
        $rawSpace = $payload['spaceName'] ?? null;
        if (is_string($rawSpace) && '' !== trim($rawSpace)) {
            $spaceName = trim($rawSpace);
        }

        try {
            $result = $this->creator->create(
                $email,
                $givenName,
                $familyName,
                null,
                $skipWaitlist,
                $spaceName,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        $user = $result['user'];
        $space = $result['space'];

        $this->logger->info('Admin created a user account.', [
            'actor' => $actor?->getEmail(),
            'created' => $user->getEmail(),
            'skipWaitlist' => $skipWaitlist,
            'withSpace' => null !== $space,
        ]);

        return $this->json([
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'givenName' => $user->getGivenName(),
            'familyName' => $user->getFamilyName(),
            'waitlisted' => $user->isWaitlisted(),
            // Shown once — the caller must capture it now.
            'password' => $result['password'],
            'space' => null === $space ? null : [
                'id' => (string) $space->getId(),
                'name' => $space->getName(),
            ],
        ], 201);
    }

    /**
     * @param array<int|string, mixed> $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
