<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Member management for an {@see Organization} (#billing Phase 1a). Admin-gated
 * (owner/admin). Existing users only — the add-by-email **invite** flow (for
 * unknown addresses) is deferred to a later sub-phase, alongside space/group
 * invites. The org must always keep at least one Owner.
 */
class OrganizationMemberController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/organizations/{id}/members', name: 'organization_add_member', methods: ['POST'])]
    public function add(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $org = $this->requireAdmin($id, $user);
        if ($org instanceof JsonResponse) {
            return $org;
        }

        $body = $this->body($request);
        $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        if ('' === $email) {
            return $this->json(['error' => 'An email is required.'], 422);
        }
        $role = $this->readRole($body['role'] ?? null);

        $target = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $target) {
            return $this->json(['error' => 'No user with that email. Invites are coming soon.'], 404);
        }

        $org->addMember($target, $role);
        $this->em->flush();

        return $this->json(['ok' => true, 'role' => $role], 201);
    }

    #[Route('/organizations/{id}/members/{userId}', name: 'organization_update_member', methods: ['PATCH'])]
    public function changeRole(string $id, string $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $org = $this->requireAdmin($id, $user);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        $target = $this->member($org, $userId);
        if (null === $target) {
            return $this->json(['error' => 'Not a member.'], 404);
        }

        $role = $this->readRole($this->body($request)['role'] ?? null);
        // Don't let the last owner be demoted.
        if (
            Organization::ROLE_OWNER === $org->roleFor($target)
            && Organization::ROLE_OWNER !== $role
            && $this->ownerCount($org) <= 1
        ) {
            return $this->json(['error' => 'An organization must keep at least one owner.'], 409);
        }

        $org->addMember($target, $role);
        $this->em->flush();

        return $this->json(['ok' => true, 'role' => $role]);
    }

    #[Route('/organizations/{id}/members/{userId}', name: 'organization_remove_member', methods: ['DELETE'])]
    public function remove(string $id, string $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        $org = $this->requireAdmin($id, $user);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        $target = $this->member($org, $userId);
        if (null === $target) {
            return $this->json(['error' => 'Not a member.'], 404);
        }
        if (Organization::ROLE_OWNER === $org->roleFor($target) && $this->ownerCount($org) <= 1) {
            return $this->json(['error' => 'An organization must keep at least one owner.'], 409);
        }

        $org->removeMember($target);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    private function requireAdmin(string $id, ?User $user): Organization|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $org = $this->em->getRepository(Organization::class)->find($id);
        // Existence-hiding: non-members get 404, non-admin members get 403.
        if (null === $org || !($this->isGranted('ROLE_ADMIN') || $org->hasMember($user))) {
            return $this->json(['error' => 'Organization not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$org->isAdmin($user)) {
            return $this->json(['error' => 'Only organization admins can manage members.'], 403);
        }

        return $org;
    }

    private function member(Organization $org, string $userId): ?User
    {
        if (!Uuid::isValid($userId)) {
            return null;
        }
        $target = $this->em->getRepository(User::class)->find(Uuid::fromString($userId));

        return null !== $target && $org->hasMember($target) ? $target : null;
    }

    private function ownerCount(Organization $org): int
    {
        $count = 0;
        foreach ($org->getMemberships() as $membership) {
            if (Organization::ROLE_OWNER === $membership->getRole()) {
                ++$count;
            }
        }

        return $count;
    }

    private function readRole(mixed $role): string
    {
        return is_string($role) && in_array($role, Organization::ALLOWED_ROLES, true)
            ? $role
            : Organization::ROLE_MEMBER;
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
