<?php

namespace App\Doctrine;

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
        return sprintf('(EXISTS(%s) OR EXISTS(%s))', $direct, $group);
    }
}
