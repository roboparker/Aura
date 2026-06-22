<?php

namespace App\Controller;

use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Service\SpaceMemberAdder;
use App\Service\UsageLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Add-by-email endpoint for space members (#186). Admin-only;
 * delegates the existing-user / unknown-email branching to
 * {@see SpaceMemberAdder} so the create-with-invites path on
 * `POST /spaces` can reuse the same logic without duplicating the
 * token rotation or invite upsert.
 */
class SpaceMemberController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpaceMemberAdder $memberAdder,
        private ValidatorInterface $validator,
        private UsageLimiter $usageLimiter,
    ) {
    }

    #[Route('/spaces/{id}/members', name: 'space_add_member', methods: ['POST'])]
    public function add(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
            return $this->json(['error' => 'Only space admins can add members.'], 403);
        }

        if ($space->isPrivate()) {
            // Visibility is the structural gate here; surfacing the
            // 409 before payload validation keeps the response shape
            // independent of whatever the client sent.
            return $this->json(
                ['error' => 'Cannot add members to a private space. Switch it to shared first.'],
                409,
            );
        }

        // Freemium gate: a free shared space is capped on members; an active
        // Team subscription lifts it. Block new adds/invites at the cap but
        // always honor invites already issued (acceptance is not gated), so
        // nobody who was invited gets stranded. No-ops while billing is dark.
        if (!$this->usageLimiter->canAddMembersToSpace($space)) {
            return $this->json([
                'error' => sprintf(
                    'Free spaces are limited to %d members. Upgrade to the Team plan to add more.',
                    $this->usageLimiter->freeSpaceMemberLimit(),
                ),
            ], 402);
        }

        $payload = $request->toArray();
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        if ('' === $email) {
            return $this->json(['error' => 'Email is required.'], 400);
        }

        $emailViolations = $this->validator->validate($email, [
            new Assert\Email(),
            new Assert\Length(max: 180),
        ]);
        if (count($emailViolations) > 0) {
            return $this->json(['error' => 'Please provide a valid email address.'], 422);
        }

        $role = is_string($payload['role'] ?? null) ? $payload['role'] : Space::ROLE_MEMBER;
        if (!in_array($role, Space::ALLOWED_ROLES, true)) {
            return $this->json(['error' => 'Role must be one of: admin, member.'], 422);
        }

        $result = $this->memberAdder->add($space, $email, $user, $role);
        if ('already_member' === $result['status']) {
            return $this->json(['error' => 'That user is already a member.'], 409);
        }

        $this->em->flush();

        if ('added' === $result['status']) {
            $candidate = $result['user'];
            return $this->json([
                'status' => 'added',
                'id' => (string) $candidate->getId(),
                '@id' => '/users/' . $candidate->getId(),
                'email' => $candidate->getEmail(),
                'role' => $role,
            ], 200);
        }

        // status === 'invited'
        $invite = $result['invite'];
        $token = $result['plainToken'];
        $this->memberAdder->sendInviteEmail($invite, $token);

        return $this->json([
            'status' => 'invited',
            'email' => $invite->getEmail(),
            'inviteId' => (string) $invite->getId(),
            'expiresAt' => $invite->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'role' => $role,
        ], 200);
    }

    #[Route('/spaces/{id}/members/{membershipId}', name: 'space_member_role', methods: ['PATCH'])]
    public function changeRole(
        string $id,
        string $membershipId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        $space = $this->adminSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        $membership = $this->findMembership($space, $membershipId);
        if (null === $membership) {
            return $this->json(['error' => 'Member not found.'], 404);
        }

        $payload = $request->toArray();
        $role = is_string($payload['role'] ?? null) ? $payload['role'] : '';
        if (!in_array($role, Space::ALLOWED_ROLES, true)) {
            return $this->json(['error' => 'Role must be one of: admin, member.'], 422);
        }

        // Never let the last admin be demoted — a space must always
        // have someone who can manage it.
        if (
            Space::ROLE_MEMBER === $role
            && Space::ROLE_ADMIN === $membership->getRole()
            && $this->adminCount($space) <= 1
        ) {
            return $this->json(['error' => 'A space must keep at least one admin.'], 409);
        }

        $membership->setRole($role);
        $this->em->flush();

        return $this->json([
            'status' => 'updated',
            'id' => (string) $membership->getId(),
            'role' => $role,
        ], 200);
    }

    #[Route('/spaces/{id}/members/{membershipId}', name: 'space_member_remove', methods: ['DELETE'])]
    public function remove(
        string $id,
        string $membershipId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        $space = $this->adminSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        $membership = $this->findMembership($space, $membershipId);
        if (null === $membership) {
            return $this->json(['error' => 'Member not found.'], 404);
        }

        // Same invariant as role changes: don't strip the final admin.
        if (Space::ROLE_ADMIN === $membership->getRole() && $this->adminCount($space) <= 1) {
            return $this->json(['error' => 'A space must keep at least one admin.'], 409);
        }

        $this->em->remove($membership);
        $this->em->flush();

        return $this->json(null, 204);
    }

    /**
     * Resolve the space and enforce admin access, or return the error
     * response to send. Mirrors the existence-hiding shape of the rest
     * of the space API (404 for non-members, 403 for non-admin members).
     */
    private function adminSpaceOr(string $id, ?User $user): Space|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$space->isAdmin($user)) {
            if (!$space->hasMember($user)) {
                return $this->json(['error' => 'Space not found.'], 404);
            }
            return $this->json(['error' => 'Only space admins can manage members.'], 403);
        }
        return $space;
    }

    private function findMembership(Space $space, string $membershipId): ?SpaceMembership
    {
        if (!Uuid::isValid($membershipId)) {
            return null;
        }
        $target = Uuid::fromString($membershipId);
        foreach ($space->getUserMemberships() as $membership) {
            if (true === $membership->getId()?->equals($target)) {
                return $membership;
            }
        }
        return null;
    }

    private function adminCount(Space $space): int
    {
        $count = 0;
        foreach ($space->getUserMemberships() as $membership) {
            if (Space::ROLE_ADMIN === $membership->getRole()) {
                ++$count;
            }
        }
        return $count;
    }
}
