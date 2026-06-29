<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Space;
use App\Entity\User;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /spaces/{id}/my-permissions` — the current user's effective per-category
 * CRUD matrix for a space (#space-roles). The PWA uses it to gate buttons so a
 * restricted member isn't shown actions that would 403. Server-side checks
 * remain the source of truth. Non-members get 404 (existence-hiding).
 */
final class MySpacePermissionsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpacePermissionResolver $permissions,
    ) {
    }

    #[Route('/spaces/{id}/my-permissions', name: 'space_my_permissions', methods: ['GET'])]
    public function __invoke(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        return $this->json(['permissions' => $this->permissions->effectiveMatrix($user, $space)]);
    }
}
