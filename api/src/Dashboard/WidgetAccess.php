<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Entity\Space;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;

/**
 * The one place that decides whether someone may see a widget (#759).
 *
 * Every read path — the dashboard listing, the per-instance data endpoint, the
 * catalog — routes through {@see canRead()}, so a widget can't end up gated one
 * way in a list and another way when fetched directly.
 *
 * The distinction that matters: admin-reserved categories (`invoices`,
 * `api_keys`) must resolve through `canByExplicitGrant()`. Plain `can()`
 * carries a back-compat rule where **a member with no assigned roles is
 * unrestricted**, which on a money widget would hand every member of every
 * space the company's revenue. Same trap the analytics metrics and the MCP
 * billing tools each had to sidestep; this is the third instance, which is why
 * it lives behind a named method rather than being re-derived per caller.
 */
final class WidgetAccess
{
    public function __construct(
        private SpacePermissionResolver $permissions,
    ) {
    }

    /**
     * Whether `$user` may see `$widget` in `$space`.
     *
     * A widget declaring no category is visible to any member — that's a claim
     * the widget makes about showing nothing space-scoped, not a fallback.
     */
    public function canRead(WidgetDefinitionInterface $widget, Space $space, User $user): bool
    {
        if (!$space->hasMember($user)) {
            return false;
        }
        if ($space->isAdmin($user)) {
            return true;
        }

        $category = $widget->permissionCategory();
        if (null === $category) {
            return true;
        }

        if (in_array($category, SpacePermission::ADMIN_RESERVED, true)) {
            return $this->permissions->canByExplicitGrant($user, $space, $category, SpacePermission::READ);
        }

        return $this->permissions->can($user, $space, $category, SpacePermission::READ);
    }

    /**
     * Whether `$user` may change the layout — add, move, resize, configure, or
     * remove widgets.
     *
     * The layout is shared by the whole space, so editing it is admin-only:
     * one member rearranging would silently change what every colleague sees.
     */
    public function canEditLayout(Space $space, User $user): bool
    {
        return $space->isAdmin($user);
    }

    /**
     * The widgets in `$space` that `$user` may add — the catalog, already
     * filtered, so the picker can't offer something that would come back empty.
     *
     * @param list<WidgetDefinitionInterface> $widgets
     *
     * @return list<WidgetDefinitionInterface>
     */
    public function filterReadable(array $widgets, Space $space, User $user): array
    {
        return array_values(array_filter(
            $widgets,
            fn (WidgetDefinitionInterface $widget) => $this->canRead($widget, $space, $user),
        ));
    }
}
