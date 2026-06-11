<?php

namespace App\Controller;

use App\Entity\Space;
use App\Entity\SpaceExport;
use App\Entity\User;
use App\Message\GenerateSpaceExport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Space data export (#space-export): request, status lookup, and download.
 *
 * `POST /spaces/{id}/export` is space-admin-gated — an export bundles every
 * member's content (tasks, pages, discussions, comments, attachments), so
 * the bar matches the other whole-space operations (delete, member
 * management). The heavy lifting runs on the worker
 * ({@see \App\MessageHandler\GenerateSpaceExportHandler}); the requester
 * gets an email with a tokenized link when the zip is ready.
 *
 * The status + download endpoints resolve the export by token (sha256-
 * compared, like password reset) AND require the caller to be signed in as
 * the requester — a leaked email alone never exposes the archive. Unknown,
 * foreign, and expired tokens all answer 404 on download so the endpoint
 * can't be used to probe which exports exist; the status endpoint
 * distinguishes `expired` for the requester so the landing page can say
 * something useful.
 */
class SpaceExportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    #[Route('/spaces/{id}/export', name: 'space_export_request', methods: ['POST'])]
    public function request(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$space->isAdmin($user)) {
            // Hide existence from non-members; non-admin members get 403.
            if (!$space->hasMember($user)) {
                return $this->json(['error' => 'Space not found.'], 404);
            }
            return $this->json(['error' => 'Only space admins can export space data.'], 403);
        }

        $inFlight = $this->em->getRepository(SpaceExport::class)->findOneBy([
            'space' => $space,
            'status' => [SpaceExport::STATUS_PENDING, SpaceExport::STATUS_PROCESSING],
        ]);
        if (null !== $inFlight) {
            return $this->json(
                ['error' => 'An export of this space is already being prepared. You\'ll get an email when it\'s ready.'],
                409,
            );
        }

        $export = new SpaceExport($space, $user);
        $this->em->persist($export);
        $this->em->flush();

        $this->bus->dispatch(new GenerateSpaceExport((string) $export->getId()));

        return $this->json([
            'id' => (string) $export->getId(),
            'status' => $export->getStatus(),
        ], 202);
    }

    #[Route('/space-exports/{token}', name: 'space_export_status', methods: ['GET'])]
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
            return $this->json(['status' => 'expired', 'spaceName' => $export->getSpace()->getName()]);
        }

        return $this->json([
            'status' => 'ready',
            'spaceName' => $export->getSpace()->getName(),
            'fileSize' => $export->getFileSize(),
            'expiresAt' => $export->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/space-exports/{token}/download', name: 'space_export_download', methods: ['GET'])]
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
            'space-export.zip',
        ));

        return $response;
    }

    /**
     * Resolves a plaintext token to the caller's own export. Foreign and
     * unknown tokens are indistinguishable (both null → 404) so the route
     * can't confirm an export's existence to anyone but its requester.
     */
    private function findByToken(string $token, User $user): ?SpaceExport
    {
        if ('' === $token || \strlen($token) > 128) {
            return null;
        }

        $export = $this->em->getRepository(SpaceExport::class)
            ->findOneBy(['tokenHash' => hash('sha256', $token)]);
        if (null === $export) {
            return null;
        }
        if (true !== $export->getRequestedBy()->getId()?->equals($user->getId())) {
            return null;
        }

        return $export;
    }

    private function downloadFileName(SpaceExport $export): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($export->getSpace()->getName()));
        $slug = (null !== $slug) ? trim($slug, '-') : '';
        if ('' === $slug) {
            $slug = 'space';
        }

        return sprintf(
            'aura-export-%s-%s.zip',
            $slug,
            $export->getCompletedAt()?->format('Y-m-d') ?? 'archive',
        );
    }
}
