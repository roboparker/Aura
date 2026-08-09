<?php

declare(strict_types=1);

namespace App\Controller;

use App\Deletion\SoftDeletionService;
use App\Entity\RestoreToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The other end of the "…is scheduled for deletion" email: look up a restore
 * link's state, and spend it.
 *
 * **Public** (declared PUBLIC_ACCESS in security.yaml). It has to be: an
 * account inside its grace period cannot sign in, so gating this behind
 * authentication would make the link useless for the case that needs it most.
 * The token is the credential, and the exposure is bounded — restoring is not
 * destructive, so the worst a leaked link does is bring back something whose
 * owner can simply delete it again.
 *
 * Statuses mirror the invite lookup so the landing page can say something
 * useful instead of a bare 404: `ready` (restorable now), `used` (someone
 * already clicked it), `expired` (the window lapsed — the purge has run or is
 * imminent), and `gone` (the target no longer exists). An *unknown* token stays
 * a flat 404 so the endpoint can't be used to enumerate them.
 */
class RestoreController extends AbstractController
{
    public function __construct(private SoftDeletionService $deletion)
    {
    }

    #[Route('/restore/{token}', name: 'restore_status', methods: ['GET'])]
    public function status(string $token): JsonResponse
    {
        $row = $this->deletion->findToken($token);
        if (null === $row) {
            return $this->json(['error' => 'This restore link is not valid.'], 404);
        }

        return $this->json($this->describe($row));
    }

    #[Route('/restore/{token}', name: 'restore_perform', methods: ['POST'])]
    public function restore(string $token): JsonResponse
    {
        $row = $this->deletion->findToken($token);
        if (null === $row) {
            return $this->json(['error' => 'This restore link is not valid.'], 404);
        }

        if (!$this->deletion->restore($row)) {
            // Already spent, lapsed, or the target is gone — report which,
            // rather than a generic failure the user can't act on.
            $state = $this->describe($row);

            return $this->json([
                'error' => match ($state['status']) {
                    'used' => 'This restore link has already been used.',
                    'expired' => 'This restore link has expired.',
                    'gone' => 'This has already been permanently deleted and cannot be restored.',
                    default => 'There is nothing to restore — it is already active.',
                },
                'status' => $state['status'],
            ], 409);
        }

        return $this->json([
            'ok' => true,
            'status' => 'restored',
            'targetType' => $row->getTargetType(),
            'label' => $row->getTargetLabel(),
        ]);
    }

    /**
     * @return array{status: string, targetType: string, label: string, expiresAt: string}
     */
    private function describe(RestoreToken $row): array
    {
        $target = $this->deletion->resolveTarget($row);
        $status = match (true) {
            $row->isUsed() => 'used',
            $row->isExpired() => 'expired',
            null === $target => 'gone',
            !$target->isDeleted() => 'active',
            default => 'ready',
        };

        return [
            'status' => $status,
            'targetType' => $row->getTargetType(),
            // Snapshotted at deletion time, so the page can name the thing even
            // when the row behind it has already been purged.
            'label' => $row->getTargetLabel(),
            'expiresAt' => $row->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
