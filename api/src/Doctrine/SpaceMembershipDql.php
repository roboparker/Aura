<?php

namespace App\Doctrine;

use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\UserGroup;

/**
 * DQL fragment helpers for "is the caller a member of the space at
 * `<alias>.space`?" (#185). Pulled out so the same EXISTS pattern
 * doesn't get copy-pasted across the access extensions, the MCP
 * tools, the media-download controller, and the task repository.
 *
 * The fragment expects the caller to:
 *  - alias the board (or any entity holding a `space` association)
 *    in their query and pass the alias in,
 *  - bind `:user` (or the parameter name they pass in) to the User.
 *
 * Each call returns a DQL boolean expression that's true when the
 * referenced space contains the user as a direct member OR via a
 * `UserGroup` owned by that space (the group's `space` FK). The
 * `$aliasPrefix` parameter prevents collisions when more than one
 * helper call appears in the same query.
 */
final class SpaceMembershipDql
{
    private function __construct()
    {
    }

    public static function userBelongsToBoardSpace(
        string $boardAlias,
        string $aliasPrefix = 'sm_check',
        string $userParam = 'user',
    ): string {
        $direct = sprintf(
            'SELECT 1 FROM %s %s_direct WHERE %s_direct.space = %s.space AND %s_direct.user = :%s',
            SpaceMembership::class,
            $aliasPrefix,
            $aliasPrefix,
            $boardAlias,
            $aliasPrefix,
            $userParam,
        );
        $group = sprintf(
            'SELECT 1 FROM %s %s_grp_obj '
            . 'JOIN %s_grp_obj.memberships %s_grp_member '
            . 'WHERE %s_grp_obj.space = %s.space AND %s_grp_member.user = :%s',
            UserGroup::class,
            $aliasPrefix,
            $aliasPrefix,
            $aliasPrefix,
            $aliasPrefix,
            $boardAlias,
            $aliasPrefix,
            $userParam,
        );
        return sprintf(
            '((EXISTS(%s) OR EXISTS(%s)) AND %s)',
            $direct,
            $group,
            self::spaceOrganizationIsLive($boardAlias, $aliasPrefix),
        );
    }

    /**
     * True unless the space's owning organization is in its post-deletion grace
     * period (#billing Phase 1c).
     *
     * Deliberately folded into {@see userBelongsToBoardSpace()} rather than
     * added at each call site: this fragment is what the access extensions, the
     * MCP tools, the media-download controller and the task repository all
     * route through, so putting it here means a deleted org's content stops
     * being reachable everywhere at once. A resource that read membership
     * through its own hand-rolled EXISTS would be the one that kept serving
     * rows, which is exactly the kind of gap this helper exists to prevent.
     *
     * A personal-account space has no organization, so the NOT EXISTS is
     * trivially true and nothing changes for it.
     */
    public static function spaceOrganizationIsLive(
        string $boardAlias,
        string $aliasPrefix = 'sm_check',
    ): string {
        return sprintf(
            'NOT EXISTS(SELECT 1 FROM %s %s_sp JOIN %s_sp.organization %s_org'
            . ' WHERE %s_sp = %s.space AND %s_org.deletedAt IS NOT NULL)',
            Space::class,
            $aliasPrefix,
            $aliasPrefix,
            $aliasPrefix,
            $aliasPrefix,
            $boardAlias,
            $aliasPrefix,
        );
    }

    /**
     * The same guard for a query whose alias **is** the Space (rather than an
     * entity holding a `space` association) — no hop through Space needed.
     */
    public static function organizationIsLive(
        string $spaceAlias,
        string $aliasPrefix = 'sm_check',
    ): string {
        return sprintf(
            'NOT EXISTS(SELECT 1 FROM %s %s_org WHERE %s_org = %s.organization AND %s_org.deletedAt IS NOT NULL)',
            Organization::class,
            $aliasPrefix,
            $aliasPrefix,
            $spaceAlias,
            $aliasPrefix,
        );
    }
}
