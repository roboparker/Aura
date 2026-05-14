<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lookup-by-email shim that adds the resolved user to the project's
 * **space** rather than to a project-local member list (#185). The URL
 * is preserved so the existing PWA — which still POSTs here — keeps
 * working; PR 4 (#187) replaces it with explicit space-management UI.
 *
 * Behaves like a "promote to space member" call: anyone in the space
 * can invite, the candidate joins as `member` (admins are managed via
 * the dedicated space endpoints in PR 3), and a 409 surfaces when the
 * user is already a direct member of the space.
 */
class ProjectMemberController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/projects/{id}/members', name: 'project_add_member', methods: ['POST'])]
    public function add(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $project = $this->em->getRepository(Project::class)->find($id);
        if (null === $project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$project->isAccessibleBy($user)) {
            // 404 mirrors the access-extension existence-hiding pattern.
            return $this->json(['error' => 'Project not found.'], 404);
        }

        $space = $project->getSpace();
        if (null === $space) {
            // Defensive: every project has a space after PR 1 (#181).
            return $this->json(['error' => 'Project is not attached to a space.'], 500);
        }

        $payload = $request->toArray();
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        if ('' === $email) {
            return $this->json(['error' => 'Email is required.'], 400);
        }

        $candidate = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $candidate) {
            return $this->json(['error' => 'No user found with that email.'], 404);
        }

        // Already a direct member — including via the auto-admin row on
        // a personal space — short-circuit with 409 so the UI can show
        // a friendly "already added" notice. Group-inherited membership
        // doesn't count as "already a member" here; we still want a
        // direct row so removal-by-PATCH works without un-grouping them.
        foreach ($space->getUserMemberships() as $existing) {
            if ($existing->getUser()?->getId()?->equals($candidate->getId())) {
                return $this->json(['error' => 'That user is already a member.'], 409);
            }
        }

        $membership = (new SpaceMembership())
            ->setUser($candidate)
            ->setRole(Space::ROLE_MEMBER);
        $space->addUserMembership($membership);
        $this->em->flush();

        return $this->json([
            'id' => (string) $candidate->getId(),
            '@id' => '/users/' . $candidate->getId(),
            'email' => $candidate->getEmail(),
        ], 200);
    }
}
