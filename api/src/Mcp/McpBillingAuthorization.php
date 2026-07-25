<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Client;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The billing half of {@see McpAuthorization} — access rules for tracked time,
 * client projects, and invoices, duplicated from the API Platform `security:`
 * expressions so MCP tools can re-check them in pure PHP.
 *
 * Kept separate from McpAuthorization because these ride two different
 * space-role categories with different defaults, and both are inherited here
 * rather than restated: {@see SpacePermission::TIME_ENTRIES} is on for a plain
 * member (everyone tracks their own time), while
 * {@see SpacePermission::INVOICES} is in {@see SpacePermission::ADMIN_RESERVED}
 * — a space admin must grant it before anyone else sees what the business
 * bills. A token can only ever narrow that further, never widen it.
 */
final class McpBillingAuthorization
{
    public function __construct(
        private readonly SpacePermissionResolver $permissions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function canReadTimeEntry(TimeEntry $entry, User $user): bool
    {
        return $this->canReadSpace($entry->getSpace(), $user, SpacePermission::TIME_ENTRIES);
    }

    /**
     * Logging time against a project. Mirrors the Post securityPostDenormalize
     * on TimeEntry: space membership plus the create permission.
     */
    public function canTrackTimeIn(?Space $space, User $user): bool
    {
        return null !== $space
            && $space->hasMember($user)
            && $this->allows($user, $space, SpacePermission::TIME_ENTRIES, SpacePermission::CREATE);
    }

    /**
     * Editing an existing entry. Mirrors the Patch expression: the tracker and
     * space admins keep their bypass, everyone else needs the update permission.
     */
    public function canEditTimeEntry(TimeEntry $entry, User $user): bool
    {
        $space = $entry->getSpace();
        if (null === $space || !$space->hasMember($user)) {
            return false;
        }

        if (true === $entry->getUser()?->getId()?->equals($user->getId()) || $space->isAdmin($user)) {
            return true;
        }

        return $this->allows($user, $space, SpacePermission::TIME_ENTRIES, SpacePermission::UPDATE);
    }

    public function canReadProject(Project $project, User $user): bool
    {
        return $this->canReadSpace($project->getSpace(), $user, SpacePermission::TIME_ENTRIES);
    }

    public function canReadClient(Client $client, User $user): bool
    {
        return $this->canReadSpace($client->getSpace(), $user, SpacePermission::INVOICES);
    }

    public function canReadInvoice(Invoice $invoice, User $user): bool
    {
        return $this->canReadSpace($invoice->getSpace(), $user, SpacePermission::INVOICES);
    }

    private function canReadSpace(?Space $space, User $user, string $category): bool
    {
        return null !== $space
            && $space->hasMember($user)
            && $this->allows($user, $space, $category, SpacePermission::READ);
    }

    /**
     * Route the check the same way {@see \App\Security\Permission\SpacePermissionVoter}
     * does, so MCP can never be more permissive than REST.
     *
     * The distinction is load-bearing: `can()` carries a back-compat rule where
     * a member with no assigned roles is unrestricted, which would hand every
     * member of every space the company's invoices. Admin-reserved categories
     * therefore need the explicit-grant sibling instead.
     */
    private function allows(User $user, ?Space $space, string $category, string $action): bool
    {
        if (in_array($category, SpacePermission::ADMIN_RESERVED, true)) {
            return $this->permissions->canByExplicitGrant($user, $space, $category, $action);
        }

        return $this->permissions->can($user, $space, $category, $action);
    }

    /**
     * Spaces the user may read `$category` in — the scoping filter every list_*
     * tool applies *before* paginating, so a page is never silently short and a
     * space the caller can't see never leaks a row.
     *
     * Membership comes from the same EXISTS fragment the access extensions use
     * (direct or group-inherited); the per-space permission check runs in PHP
     * because the resolver memoises it and a user belongs to few spaces.
     *
     * @return list<Space>
     */
    public function readableSpaces(User $user, string $category): array
    {
        // Direct or group-inherited membership. Written inline rather than via
        // SpaceMembershipDql because that helper expects an alias *holding* a
        // space association, and here the space is the root entity.
        $direct = sprintf(
            'SELECT 1 FROM %s bm_direct WHERE bm_direct.space = s AND bm_direct.user = :user',
            SpaceMembership::class,
        );
        $viaGroup = sprintf(
            'SELECT 1 FROM %s bm_group JOIN bm_group.memberships bm_member '
            . 'WHERE bm_group.space = s AND bm_member.user = :user',
            UserGroup::class,
        );

        $spaces = $this->em->getRepository(Space::class)
            ->createQueryBuilder('s')
            ->where(sprintf('(EXISTS(%s) OR EXISTS(%s))', $direct, $viaGroup))
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $spaces,
            fn (Space $space) => $this->allows($user, $space, $category, SpacePermission::READ),
        ));
    }
}
