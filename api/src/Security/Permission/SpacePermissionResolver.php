<?php

declare(strict_types=1);

namespace App\Security\Permission;

use App\Entity\Space;
use App\Entity\SpaceMembership;
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

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function can(User $user, ?Space $space, string $category, string $action): bool
    {
        if (null === $space) {
            return true;
        }

        $membership = $this->directMembership($space, $user);
        if (null === $membership) {
            // No direct membership: either group-inherited (unrestricted in v1)
            // or not a member at all (denied — the access layer 404s them too).
            return $space->hasMember($user);
        }
        if (Space::ROLE_ADMIN === $membership->getRole()) {
            return true;
        }
        $roles = $membership->getRoles();
        if ($roles->isEmpty()) {
            return true;
        }
        foreach ($roles as $role) {
            if ($role->allows($category, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Space UUIDs where the user is DENIED read for a category — used by the
     * access extensions to drop unreadable rows. Empty for unrestricted users
     * (admins / zero-role / group-only), so the common case adds no DQL and
     * today's behaviour is unchanged.
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
        foreach ($this->restrictedMemberships($user) as $membership) {
            $space = $membership->getSpace();
            if (null === $space || null === $space->getId()) {
                continue;
            }
            $allowed = false;
            foreach ($membership->getRoles() as $role) {
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

    /**
     * The user's direct memberships that could be restricted — non-admin and
     * carrying at least one role.
     *
     * @return list<SpaceMembership>
     */
    private function restrictedMemberships(User $user): array
    {
        $out = [];
        foreach ($this->em->getRepository(SpaceMembership::class)->findBy(['user' => $user]) as $membership) {
            if (Space::ROLE_ADMIN === $membership->getRole()) {
                continue;
            }
            if ($membership->getRoles()->isEmpty()) {
                continue;
            }
            $out[] = $membership;
        }

        return $out;
    }
}
