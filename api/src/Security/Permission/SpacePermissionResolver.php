<?php

declare(strict_types=1);

namespace App\Security\Permission;

use App\Entity\ApiToken;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves a user's effective per-space permissions from their assigned roles
 * (#space-roles, Phase 2). Single source of truth for the voter (item/create/
 * update/delete) and the access extensions (collection read filtering).
 *
 * Rules (see plan):
 *  - No space → allowed (owner-only / personal content has no space gate).
 *  - Space admin → allowed (never restricted).
 *  - Direct member with **zero** roles → allowed (back-compat; unrestricted).
 *  - Direct member with roles → allowed iff ANY role grants (category, action).
 *  - Group-inherited access (no direct membership) → allowed (full, v1).
 */
final class SpacePermissionResolver
{
    /** @var array<string, list<string>> memo: "userId|category" => denied space UUIDs */
    private array $readDeniedMemo = [];
    /** @var array<string, ?SpaceRole> memo: spaceId => built-in Member role */
    private array $memberRoleMemo = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActorContext $actor,
    ) {
    }

    public function can(User $user, ?Space $space, string $category, string $action): bool
    {
        // A space-scoped API key ignores the creator's own access entirely:
        // confined to its space + the union of its roles (no role ⇒ nothing).
        $key = $this->actor->scopedKey();
        if (null !== $key) {
            return $this->keyAllows($key, $space, $category, $action);
        }

        if (null === $space) {
            return true;
        }

        $membership = $this->directMembership($space, $user);
        if (null !== $membership && Space::ROLE_ADMIN === $membership->getRole()) {
            return true;
        }

        $roles = $this->effectiveRoles($space, $membership);
        if (null === $roles) {
            // No explicit roles AND no seeded Member role (legacy/test space) →
            // unrestricted, but only for an actual member of the space.
            return null !== $membership || $space->hasMember($user);
        }
        foreach ($roles as $role) {
            if ($role->allows($category, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A space-scoped key may act on a subject only when the subject is in the
     * key's space (confinement) AND one of the key's roles grants the action.
     */
    public function keyAllows(ApiToken $key, ?Space $space, string $category, string $action): bool
    {
        $keySpace = $key->getSpace();
        if (null === $keySpace || null === $space || null === $space->getId()) {
            return false;
        }
        if ((string) $space->getId() !== (string) $keySpace->getId()) {
            return false;
        }

        return $this->keyAllowsCategory($key, $category, $action);
    }

    /**
     * Whether any of the key's roles grants (category, action) — the space-less
     * check used by the request listener (confinement is enforced separately by
     * the access extensions).
     */
    public function keyAllowsCategory(ApiToken $key, string $category, string $action): bool
    {
        foreach ($key->getRoles() as $role) {
            if ($role->allows($category, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The roles that decide a (non-admin) member's access in a space: their
     * explicitly-assigned roles, or the space's built-in Member role when none
     * are assigned (incl. group-only access). Null = no roles + no Member role.
     *
     * @return list<SpaceRole>|null
     */
    private function effectiveRoles(Space $space, ?SpaceMembership $membership): ?array
    {
        if (null !== $membership && !$membership->getRoles()->isEmpty()) {
            return array_values($membership->getRoles()->toArray());
        }
        $member = $this->memberRole($space);

        return null === $member ? null : [$member];
    }

    private function memberRole(Space $space): ?SpaceRole
    {
        $id = (string) $space->getId();
        if (array_key_exists($id, $this->memberRoleMemo)) {
            return $this->memberRoleMemo[$id];
        }

        return $this->memberRoleMemo[$id] = $this->em->getRepository(SpaceRole::class)
            ->findOneBy(['space' => $space, 'builtinKey' => SpaceRole::BUILTIN_MEMBER]);
    }

    /**
     * Space UUIDs where the user is DENIED read for a category — used by the
     * access extensions to drop unreadable rows. Walks the user's direct
     * memberships, using each membership's explicit roles or the space's Member
     * default. Empty when nothing restricts read (e.g. a full Member role), so
     * the common case adds no DQL and behaviour is unchanged. (Group-only
     * spaces aren't collection-filtered in v1; item access is still gated.)
     *
     * @return list<string>
     */
    public function readDeniedSpaceIds(User $user, string $category): array
    {
        $key = $user->getId() . '|' . $category;
        if (isset($this->readDeniedMemo[$key])) {
            return $this->readDeniedMemo[$key];
        }

        $denied = [];
        foreach ($this->em->getRepository(SpaceMembership::class)->findBy(['user' => $user]) as $membership) {
            if (Space::ROLE_ADMIN === $membership->getRole()) {
                continue;
            }
            $space = $membership->getSpace();
            if (null === $space || null === $space->getId()) {
                continue;
            }
            $roles = $this->effectiveRoles($space, $membership);
            if (null === $roles) {
                continue; // no roles + no Member role → unrestricted
            }
            $allowed = false;
            foreach ($roles as $role) {
                if ($role->allows($category, SpacePermission::READ)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $denied[] = (string) $space->getId();
            }
        }

        return $this->readDeniedMemo[$key] = $denied;
    }

    /**
     * The user's full effective permission matrix for a space — every category
     * × action resolved through {@see can()}. Backs the PWA's UI gating.
     *
     * @return array<string, array<string, bool>>
     */
    public function effectiveMatrix(User $user, Space $space): array
    {
        $matrix = [];
        foreach (SpacePermission::CATEGORIES as $category) {
            foreach (SpacePermission::ACTIONS as $action) {
                $matrix[$category][$action] = $this->can($user, $space, $category, $action);
            }
        }

        return $matrix;
    }

    private function directMembership(Space $space, User $user): ?SpaceMembership
    {
        if (null === $space->getId() || null === $user->getId()) {
            return null;
        }

        return $this->em->getRepository(SpaceMembership::class)
            ->findOneBy(['space' => $space, 'user' => $user]);
    }
}
