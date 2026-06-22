<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountExport;
use App\Entity\CancellationFeedback;
use App\Entity\User;
use App\Message\GenerateAccountExport;
use App\Repository\UserSessionRepository;
use App\Service\AccountDeletionService;
use App\Service\CancellationFeedbackRecorder;
use App\Service\SensitiveActionVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Account lifecycle actions for the Settings → Danger zone: deactivate
 * (soft, reversible on next sign-in), export (async GDPR archive), and delete
 * (hard, with author anonymization). All three are step-up protected via
 * {@see SensitiveActionVerifier}.
 */
class AccountLifecycleController extends AbstractController
{
    use JsonRequestTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SensitiveActionVerifier $verifier,
        private readonly UserSessionRepository $sessions,
        private readonly AccountDeletionService $deletion,
        private readonly CancellationFeedbackRecorder $feedback,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/me/deactivate', name: 'me_deactivate', methods: ['POST'])]
    public function deactivate(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $body = $this->jsonBody($request);
        $err = $this->verifier->verify($user, $body);
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }
        $reasonError = $this->feedback->reasonError($body);
        if (null !== $reasonError) {
            return $this->json(['error' => $reasonError], 422);
        }

        $this->feedback->record(CancellationFeedback::CONTEXT_ACCOUNT_DEACTIVATION, $user, null, $body);

        $user->setDeactivatedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->sessions->revokeAllForUser($user);
        $this->endSession($request);

        return $this->json(['ok' => true]);
    }

    /**
     * Queues a GDPR data export. The archive is built asynchronously
     * ({@see \App\MessageHandler\GenerateAccountExportHandler}) and a
     * tokenized download link is emailed to the requester when ready;
     * {@see \App\Controller\AccountExportController} serves the status +
     * bytes. Only one export per user is built at a time — a second request
     * while one is in flight gets a 409.
     */
    #[Route('/me/export', name: 'me_export', methods: ['POST'])]
    public function export(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $err = $this->verifier->verify($user, $this->jsonBody($request));
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }

        $inFlight = $this->em->getRepository(AccountExport::class)->findOneBy([
            'requestedBy' => $user,
            'status' => [AccountExport::STATUS_PENDING, AccountExport::STATUS_PROCESSING],
        ]);
        if (null !== $inFlight) {
            return $this->json(
                ['error' => 'An export is already being prepared. You\'ll get an email when it\'s ready.'],
                409,
            );
        }

        $export = new AccountExport($user);
        $this->em->persist($export);
        $this->em->flush();

        $this->bus->dispatch(new GenerateAccountExport((string) $export->getId()));

        return $this->json([
            'id' => (string) $export->getId(),
            'status' => $export->getStatus(),
        ], 202);
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
        $reasonError = $this->feedback->reasonError($body);
        if (null !== $reasonError) {
            return $this->json(['error' => $reasonError], 422);
        }

        // Record before deletion — the user FK is SET NULL on delete, leaving
        // the (now anonymized) feedback behind.
        $this->feedback->record(CancellationFeedback::CONTEXT_ACCOUNT_DELETION, $user, null, $body);

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
