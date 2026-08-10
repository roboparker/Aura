<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use App\Service\AgentDeletionService;
use App\Service\AgentProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * AI agents for a space (#827), managed from the space Users page alongside
 * human members.
 *
 * An agent is a {@see User} row flagged `isAgent` with a {@see SpaceMembership}
 * and a space-scoped API token — see {@see AgentProvisioner} for why all three
 * are reused rather than replaced.
 *
 * **Gated on the `api_keys` permission**, not on space admin directly, which
 * puts agents under exactly the capability that already governs
 * {@see SpaceApiKeyController}. That is the honest classification: creating an
 * agent mints a Bearer credential confined to this space and narrowed to the
 * chosen roles, which is precisely what creating a space API key does. The
 * category is admin-reserved, so admins hold it by default and a member holds
 * it only through a role that grants it explicitly.
 */
final class SpaceAgentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpacePermissionResolver $permissions,
        private readonly AgentProvisioner $provisioner,
        private readonly AgentDeletionService $deletions,
    ) {
    }

    /**
     * The space's agents, readable by **any member**.
     *
     * Deliberately a weaker gate than the writes below. Creating an agent mints
     * a credential, which is `api_keys` work; *seeing* that the space has one is
     * no more sensitive than seeing the human roster, which every member can
     * already read — and step 3 opened chatting to every member, so a gate here
     * would list agents only to the people least likely to want to talk to one.
     * The response carries no secret: a token is shown exactly once, at
     * creation, and never appears in this payload.
     */
    #[Route('/spaces/{id}/agents', name: 'space_agents_list', methods: ['GET'])]
    public function list(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->memberSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }

        return $this->json(['agents' => $this->serializeAll($space)]);
    }

    #[Route('/spaces/{id}/agents', name: 'space_agents_create', methods: ['POST'])]
    public function create(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->permittedSpaceOr($id, $user, SpacePermission::CREATE);
        if ($space instanceof JsonResponse) {
            return $space;
        }

        // A private space is one person's own; there is nobody for an agent to
        // collaborate with and no admin/member distinction to scope it by.
        // Mirrors the same refusal on adding a human member.
        if ($space->isPrivate()) {
            return $this->json(
                ['error' => 'Cannot add agents to a private space. Switch it to shared first.'],
                409,
            );
        }

        $payload = $request->toArray();
        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ('' === $name || mb_strlen($name) > AgentProvisioner::MAX_NAME_LENGTH) {
            return $this->json(
                ['error' => sprintf('An agent name (1–%d chars) is required.', AgentProvisioner::MAX_NAME_LENGTH)],
                422,
            );
        }

        $roles = $this->resolveRoles($space, $payload['roles'] ?? []);
        if ($roles instanceof JsonResponse) {
            return $roles;
        }

        $result = $this->provisioner->provision($space, $name, $roles);
        $this->em->flush();

        return $this->json([
            ...$this->serialize($result['agent'], $result['membership']),
            // The one and only time the bearer is visible; only its hash is
            // stored, so a lost token means provisioning a new agent.
            'plainToken' => $result['plainToken'],
        ], 201);
    }

    /**
     * Change which roles an agent may act under.
     *
     * Both the membership and the token are rewritten. They are separate
     * grants and either one alone would be a hole: leaving the token's roles
     * behind would let a narrowed agent keep acting on its old ceiling, and
     * leaving the membership's behind would strand permissions the resolver
     * still honours.
     */
    #[Route('/spaces/{id}/agents/{agentId}', name: 'space_agents_update', methods: ['PATCH'])]
    public function update(
        string $id,
        string $agentId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        $space = $this->permittedSpaceOr($id, $user, SpacePermission::UPDATE);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        $membership = $this->findAgentMembership($space, $agentId);
        if (null === $membership) {
            return $this->json(['error' => 'Agent not found.'], 404);
        }
        $agent = $membership->getUser();
        if (null === $agent) {
            return $this->json(['error' => 'Agent not found.'], 404);
        }

        $payload = $request->toArray();

        if (array_key_exists('name', $payload)) {
            $name = is_string($payload['name']) ? trim($payload['name']) : '';
            if ('' === $name || mb_strlen($name) > AgentProvisioner::MAX_NAME_LENGTH) {
                return $this->json(
                    ['error' => sprintf('An agent name (1–%d chars) is required.', AgentProvisioner::MAX_NAME_LENGTH)],
                    422,
                );
            }
            $agent->setNickname(mb_substr($name, 0, 100));
            $agent->setGivenName(mb_substr($name, 0, 100));
        }

        if (array_key_exists('roles', $payload)) {
            $roles = $this->resolveRoles($space, $payload['roles']);
            if ($roles instanceof JsonResponse) {
                return $roles;
            }
            $membership->clearRoles();
            foreach ($roles as $role) {
                $membership->addRole($role);
            }
            foreach ($this->provisioner->tokensFor($agent) as $token) {
                $token->clearRoles();
                foreach ($roles as $role) {
                    $token->addRole($role);
                }
            }
        }

        $this->em->flush();

        return $this->json($this->serialize($agent, $membership));
    }

    /**
     * Remove an agent entirely — its membership, its credentials and the row
     * itself.
     *
     * Deleting rather than scheduling is right *for an agent*: it is a
     * credential, not a person, so there is no account holder with a claim to a
     * grace period and nothing to email a restore link to. What it wrote is
     * preserved — {@see AgentDeletionService} reassigns authorship to the
     * "Removed agent" placeholder rather than letting the FK cascades take it.
     */
    #[Route('/spaces/{id}/agents/{agentId}', name: 'space_agents_delete', methods: ['DELETE'])]
    public function delete(string $id, string $agentId, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->permittedSpaceOr($id, $user, SpacePermission::DELETE);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        $membership = $this->findAgentMembership($space, $agentId);
        if (null === $membership) {
            return $this->json(['error' => 'Agent not found.'], 404);
        }
        $agent = $membership->getUser();
        if (null === $agent) {
            return $this->json(['error' => 'Agent not found.'], 404);
        }

        $this->deletions->delete($agent);

        return $this->json(null, 204);
    }

    /**
     * The space's agents, newest first, as membership rows.
     *
     * @return list<array<string, mixed>>
     */
    private function serializeAll(Space $space): array
    {
        $agents = [];
        foreach ($space->getUserMemberships() as $membership) {
            $candidate = $membership->getUser();
            if (null === $candidate || !$candidate->isAgent()) {
                continue;
            }
            $agents[] = $this->serialize($candidate, $membership);
        }

        return $agents;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(User $agent, SpaceMembership $membership): array
    {
        $roles = [];
        foreach ($membership->getRoles() as $role) {
            $roles[] = [
                '@id' => '/space_roles/' . $role->getId(),
                'id' => (string) $role->getId(),
                'name' => $role->getName(),
                'color' => $role->getColor(),
            ];
        }

        return [
            'id' => (string) $agent->getId(),
            '@id' => '/users/' . $agent->getId(),
            'name' => $agent->getNickname() ?? $agent->getGivenName(),
            'membershipId' => (string) $membership->getId(),
            'personalizedColor' => $agent->getPersonalizedColor(),
            'roles' => $roles,
            'createdAt' => $agent->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Resolve SpaceRole IRIs/UUIDs to entities belonging to this space, or the
     * error response to return. An empty list is valid — an agent with no
     * roles can do nothing, which is a reasonable state to park one in.
     *
     * @return list<SpaceRole>|JsonResponse
     */
    private function resolveRoles(Space $space, mixed $raw): array|JsonResponse
    {
        if (!is_array($raw)) {
            return $this->json(['error' => 'roles must be an array of role IRIs.'], 422);
        }
        $roles = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                return $this->json(['error' => 'Each role must be an IRI string.'], 422);
            }
            $uuid = substr($entry, (int) strrpos($entry, '/') + 1);
            if (!Uuid::isValid($uuid)) {
                return $this->json(['error' => sprintf('Invalid role IRI: %s', $entry)], 422);
            }
            $role = $this->em->getRepository(SpaceRole::class)->find($uuid);
            if (null === $role || true !== $role->getSpace()?->getId()?->equals($space->getId())) {
                return $this->json(['error' => 'Role does not belong to this space.'], 422);
            }
            $roles[] = $role;
        }

        return $roles;
    }

    /**
     * The membership of an agent in this space, or null. Requires the target
     * to actually be an agent, so these routes can never be turned on a human
     * member — deleting one here would skip every invariant
     * {@see SpaceMemberController} enforces (last-admin, org roster, seats).
     */
    private function findAgentMembership(Space $space, string $agentId): ?SpaceMembership
    {
        if (!Uuid::isValid($agentId)) {
            return null;
        }
        $target = Uuid::fromString($agentId);
        foreach ($space->getUserMemberships() as $membership) {
            $candidate = $membership->getUser();
            if (null !== $candidate && $candidate->isAgent() && true === $candidate->getId()?->equals($target)) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * Resolve the space and authorize the caller for the given agent action.
     * Access = platform admin, OR the `api_keys` permission for this space.
     * Non-members get a 404 so the space's existence stays hidden, matching
     * the rest of the space API.
     */
    private function permittedSpaceOr(string $id, ?User $user, string $action): Space|JsonResponse
    {
        $space = $this->memberSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        if ($this->isGranted('ROLE_ADMIN')) {
            return $space;
        }
        \assert($user instanceof User);
        if (!$this->permissions->canByExplicitGrant($user, $space, SpacePermission::API_KEYS, $action)) {
            return $this->json(['error' => 'You do not have permission to manage agents.'], 403);
        }

        return $space;
    }

    /**
     * Resolve the space, requiring only membership. Non-members get a 404 so
     * the space's existence stays hidden, matching the rest of the space API.
     */
    private function memberSpaceOr(string $id, ?User $user): Space|JsonResponse
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
        if (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        return $space;
    }
}
