<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserSessionRepository;
use App\Service\AccountDeletionService;
use App\Service\SensitiveActionVerifier;
use App\Service\UserDataExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Account lifecycle actions for the Settings → Danger zone: deactivate
 * (soft, reversible on next sign-in), export (GDPR JSON), and delete (hard,
 * with author anonymization). All three are step-up protected via
 * {@see SensitiveActionVerifier}.
 */
class AccountLifecycleController extends AbstractController
{
    use JsonRequestTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SensitiveActionVerifier $verifier,
        private readonly UserSessionRepository $sessions,
        private readonly UserDataExporter $exporter,
        private readonly AccountDeletionService $deletion,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/me/deactivate', name: 'me_deactivate', methods: ['POST'])]
    public function deactivate(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $err = $this->verifier->verify($user, $this->jsonBody($request));
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }

        $user->setDeactivatedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->sessions->revokeAllForUser($user);
        $this->endSession($request);

        return $this->json(['ok' => true]);
    }

    #[Route('/me/export', name: 'me_export', methods: ['POST'])]
    public function export(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $err = $this->verifier->verify($user, $this->jsonBody($request));
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }

        $data = $this->exporter->export($user);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = new JsonResponse(false === $json ? '{}' : $json, 200, [], true);
        $response->headers->set('Content-Disposition', 'attachment; filename="aura-export.json"');

        return $response;
    }

    #[Route('/me/delete', name: 'me_delete', methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $body = $this->jsonBody($request);

        $confirm = $body['confirmEmail'] ?? null;
        if (!is_string($confirm) || strtolower(trim($confirm)) !== strtolower($user->getEmail())) {
            return $this->json(['error' => 'Type your email exactly to confirm.'], 422);
        }

        $err = $this->verifier->verify($user, $body);
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }

        $this->deletion->deleteAccount($user);
        $this->endSession($request);

        return new JsonResponse(null, 204);
    }

    private function endSession(Request $request): void
    {
        $this->tokenStorage->setToken(null);
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }
    }
}
