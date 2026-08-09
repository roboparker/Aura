<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Space;
use App\Entity\User;
use App\Repository\SpaceRepository;
use App\Service\SensitiveActionVerifier;
use App\Service\SpaceDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * In-app restore for a space inside its deletion grace period, and the listing
 * that makes it findable.
 *
 * The emailed link ({@see RestoreController}) covers the person who deleted it;
 * this covers the admin who is already signed in and doesn't want to go hunting
 * through their inbox. A deleted space drops out of `GET /spaces`, so without
 * the listing there'd be no way to reach it.
 */
class SpaceRestoreController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpaceRepository $spaces,
        private SpaceDeletionService $deletion,
        private SensitiveActionVerifier $verifier,
    ) {
    }

    /**
     * Spaces the caller admins that are scheduled for deletion.
     *
     * Route priority beats API Platform's `/spaces/{id}` item route, which
     * would otherwise swallow "deleted" as an id.
     */
    #[Route('/spaces/deleted', name: 'space_deleted_list', methods: ['GET'], priority: 10)]
    public function deleted(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $out = [];
        foreach ($this->spaces->findBy(['isPersonal' => false]) as $space) {
            if (!$space->isDeleted() || !$space->isAdmin($user)) {
                continue;
            }
            $organization = $space->getOrganization();
            $out[] = [
                'id' => (string) $space->getId(),
                'name' => $space->getName(),
                'deletedAt' => $space->getDeletedAt()?->format(\DateTimeInterface::ATOM),
                'purgeAfter' => $space->getPurgeAfter()?->format(\DateTimeInterface::ATOM),
                // A space inside a deleted org can't be restored on its own —
                // the org purge takes it either way, so the UI offers restoring
                // the organization instead of a button that wouldn't stick.
                'blockedByOrganization' => null !== $organization && $organization->isDeleted(),
            ];
        }

        return $this->json(['spaces' => $out]);
    }

    #[Route('/spaces/{id}/restore', name: 'space_restore', methods: ['POST'])]
    public function restore(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        // Existence-hiding matches the rest of the space API.
        if (null === $space || !$space->hasMember($user)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        if (!$space->isAdmin($user)) {
            return $this->json(['error' => 'Only space admins can restore a space.'], 403);
        }
        if (!$space->isDeleted()) {
            return $this->json(['error' => 'This space is not scheduled for deletion.'], 409);
        }

        $organization = $space->getOrganization();
        if (null !== $organization && $organization->isDeleted()) {
            return $this->json([
                'error' => 'This space belongs to an organization that is scheduled for deletion. '
                    . 'Restore the organization instead.',
            ], 409);
        }

        // Step-up, same as the delete it reverses: turning access to everyone's
        // content back on is a security event even though it isn't destructive.
        $body = $this->body($request);
        if (null !== ($stepUp = $this->verifier->verify($user, $body))) {
            return $this->json(['error' => $stepUp[1]], $stepUp[0]);
        }

        $this->deletion->restore($space);

        return $this->json(['ok' => true, 'status' => 'active']);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
