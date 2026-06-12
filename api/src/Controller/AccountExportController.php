<?php

namespace App\Controller;

use App\Entity\AccountExport;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * GDPR account data export (#account-export): status lookup + download. The
 * request side lives on {@see AccountLifecycleController::export} (`POST
 * /me/export`), which is step-up protected; the heavy lifting runs on the
 * worker ({@see \App\MessageHandler\GenerateAccountExportHandler}) and the
 * requester gets an email with a tokenized link when the zip is ready.
 *
 * Both endpoints resolve the export by token (sha256-compared, like password
 * reset) AND require the caller to be signed in as the requester — a leaked
 * email alone never exposes the archive, and unlike a space export there is
 * no admin override: account data is the requester's alone. Unknown, foreign,
 * and expired tokens all answer 404 on download so the route can't be used to
 * probe which exports exist; the status endpoint distinguishes `expired` for
 * the requester so the landing page can say something useful.
 */
class AccountExportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/account-exports/{token}', name: 'account_export_status', methods: ['GET'])]
    public function status(string $token, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $export = $this->findByToken($token, $user);
        if (null === $export) {
            return $this->json(['error' => 'Export not found.'], 404);
        }

        if (!$export->isDownloadable()) {
            return $this->json(['status' => 'expired']);
        }

        return $this->json([
            'status' => 'ready',
            'fileSize' => $export->getFileSize(),
            'expiresAt' => $export->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/account-exports/{token}/download', name: 'account_export_download', methods: ['GET'])]
    public function download(string $token, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $export = $this->findByToken($token, $user);
        if (null === $export || !$export->isDownloadable()) {
            return $this->json(['error' => 'Export not found.'], 404);
        }

        $path = $export->getFilePath();
        if (null === $path || !is_file($path)) {
            return $this->json(['error' => 'Export not found.'], 404);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $this->downloadFileName($export),
            'account-export.zip',
        ));

        return $response;
    }

    /**
     * Resolves a plaintext token to an export the caller may access: only the
     * requester. Account data is personal, so there is no admin override —
     * the download bar matches the (self-service) request bar. Foreign and
     * unknown tokens are indistinguishable (both null → 404) so the route
     * can't confirm an export's existence to anyone but its owner.
     */
    private function findByToken(string $token, User $user): ?AccountExport
    {
        if ('' === $token || \strlen($token) > 128) {
            return null;
        }

        $export = $this->em->getRepository(AccountExport::class)
            ->findOneBy(['tokenHash' => hash('sha256', $token)]);
        if (null === $export) {
            return null;
        }

        $isRequester = true === $export->getRequestedBy()->getId()?->equals($user->getId());

        return $isRequester ? $export : null;
    }

    private function downloadFileName(AccountExport $export): string
    {
        return sprintf(
            'madori-account-export-%s.zip',
            $export->getCompletedAt()?->format('Y-m-d') ?? 'archive',
        );
    }
}
