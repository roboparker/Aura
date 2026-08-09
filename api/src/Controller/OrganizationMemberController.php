<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Service\OrganizationGuestPolicy;
use App\Service\OrganizationSeatSync;
use App\Service\UsageLimiter;
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
    public function __construct(
        private EntityManagerInterface $em,
        private OrganizationSeatSync $seatSync,
        private OrganizationGuestPolicy $guestPolicy,
        private UsageLimiter $usageLimiter,
    ) {
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

        // The free seat cap. Guests are free, so only a billable role is
        // checked — and this is the one place a seat can be taken without
        // touching a space at all, which is why the check has to live here as
        // well as on the space endpoint.
        if (
            Organization::ROLE_GUEST !== $role
            && !$org->hasMember($target)
            && !$this->usageLimiter->canAddMembersToOrganization($org)
        ) {
            return $this->json([
                'error' => sprintf(
                    'Free organizations are limited to %d seats. Upgrade to add more, or add them as a guest.',
                    $this->usageLimiter->freeSpaceMemberLimit(),
                ),
            ], 402);
        }

        $org->addMember($target, $role);
        $this->em->flush();
        $this->syncSeatsIfBillable($org, $role);

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

        $previous = $org->roleFor($target);
        $role = $this->readRole($this->body($request)['role'] ?? null);
        // Don't let the last owner be demoted.
        if (
            Organization::ROLE_OWNER === $previous
            && Organization::ROLE_OWNER !== $role
            && $this->ownerCount($org) <= 1
        ) {
            return $this->json(['error' => 'An organization must keep at least one owner.'], 409);
        }

        // Demoting to Guest has to reach back into the spaces they're already
        // in, or the account would say "guest" while the spaces still said
        // "admin" (#billing Phase 1c). Refuse when that would strand a space
        // with no admin — the org admin needs to appoint one first, and doing
        // it for them by picking an arbitrary member would be worse.
        $demotingToGuest = Organization::ROLE_GUEST === $role && Organization::ROLE_GUEST !== $previous;
        if ($demotingToGuest) {
            $stranded = $this->guestPolicy->spacesSolelyAdminedBy($org, $target);
            if ([] !== $stranded) {
                return $this->json([
                    'error' => 'This member is the only admin of ' . \count($stranded)
                        . ' space(s). Promote another admin there before making them a guest.',
                    'spaces' => array_map(
                        static fn ($space) => ['id' => (string) $space->getId(), 'name' => $space->getName()],
                        $stranded,
                    ),
                ], 409);
            }
        }

        $org->addMember($target, $role);
        if ($demotingToGuest) {
            $this->guestPolicy->applyGuestCap($org, $target);
        }
        $this->em->flush();

        // A role change crosses the seat boundary in either direction: guest →
        // anything adds a seat, anything → guest frees one.
        if (Organization::ROLE_GUEST === $role || Organization::ROLE_GUEST === $previous) {
            $this->seatSync->schedule($org);
        }

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

        $wasBillable = Organization::ROLE_GUEST !== $org->roleFor($target);
        $org->removeMember($target);
        $this->em->flush();
        if ($wasBillable) {
            $this->seatSync->schedule($org);
        }

        return $this->json(['ok' => true]);
    }

    /** Queue a seat push when the change actually moved the billable count. */
    private function syncSeatsIfBillable(Organization $org, string $role): void
    {
        if (Organization::ROLE_GUEST !== $role) {
            $this->seatSync->schedule($org);
        }
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
