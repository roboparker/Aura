<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lookup-by-email endpoint for adding a project member without exposing
 * the full user directory. Resolution is server-side; existing members
 * (and admins) can invite by typing the exact email address.
 *
 * Member removal stays on the standard PATCH /projects/{id} endpoint —
 * the client just sends a filtered `members` array.
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

        if (!$this->isGranted('ROLE_ADMIN') && !$project->getMembers()->contains($user)) {
            return $this->json(['error' => 'You are not a member of this project.'], 403);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        if ('' === $email) {
            return $this->json(['error' => 'Email is required.'], 400);
        }

        $candidate = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $candidate) {
            return $this->json(['error' => 'No user found with that email.'], 404);
        }

        if ($project->getMembers()->contains($candidate)) {
            return $this->json(['error' => 'That user is already a member.'], 409);
        }

        $project->addMember($candidate);
        $this->em->flush();

        return $this->json([
            'id' => (string) $candidate->getId(),
            '@id' => '/users/' . $candidate->getId(),
            'email' => $candidate->getEmail(),
        ], 200);
    }
}
