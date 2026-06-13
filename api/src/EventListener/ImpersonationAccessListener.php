<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Uid\Uuid;

/**
 * Scopes what an admin can do/see WHILE impersonating, per the target user's
 * impersonation consent — both the per-category matrix (`impersonationAccess`)
 * and per-item overrides (`impersonationItemAccess`).
 *
 * The master gate (may this admin impersonate this user at all?) is
 * App\Security\ImpersonationVoter. Once a session is impersonating — i.e.
 * carries a SwitchUserToken — this listener narrows it further. Each request
 * maps to an action by HTTP method (GET/HEAD = view, else = edit) and to one
 * of three shapes by path:
 *
 *  - Item route of an addressable type (`/projects/{id}`, `/pages/{id}`,
 *    `/tasks/{id}`, `/discussions/{id}`, and their sub-resources): governed by
 *    the EFFECTIVE item level (per-item override, else the category default).
 *  - Collection of an addressable type (`/projects`, `/tasks`, …): reads are
 *    always allowed because ImpersonationItemScope filters the rows down to
 *    what's viewable (an empty list, never a leak); writes (e.g. POST create)
 *    require the category to be 'edit'.
 *  - Any other content category (`/comments`, `/notifications`, `/media-objects`):
 *    governed wholesale by the category level.
 *  - Non-content endpoints (app shell, `/api/me`, `/spaces`, `/groups`, auth):
 *    reads allowed so the impersonated UI loads and the admin can navigate +
 *    stop impersonating; ALL writes blocked — which also prevents an
 *    impersonator from editing the target's own `/me/preferences` (and thus
 *    the consent matrix).
 *
 * Account-takeover actions (change password, disable 2FA, delete/export the
 * account) are independently protected by SensitiveActionVerifier's step-up
 * (TOTP / current password), which an impersonator cannot satisfy.
 *
 * Runs after the firewall (which authenticates + resolves switch_user at
 * priority 8) so the token reflects the post-switch state. Switch-in and
 * switch-out short-circuit with a redirect inside the firewall, so this
 * listener only sees steady-state impersonated requests.
 */
#[AsEventListener(event: 'kernel.request', priority: 4)]
final class ImpersonationAccessListener
{
    /**
     * First path segment → content category. Anything not listed here is
     * treated as a non-content endpoint (reads allowed, writes blocked).
     *
     * @var array<string, string>
     */
    private const PREFIX_TO_CATEGORY = [
        'tasks' => 'tasks',
        'tags' => 'tasks',
        'projects' => 'projects',
        'custom_field_definitions' => 'projects',
        'pages' => 'pages',
        'discussions' => 'discussions',
        'comments' => 'comments',
        'notifications' => 'notifications',
        'media-objects' => 'files',
    ];

    /**
     * First path segment → addressable item type (the prefixes that carry
     * per-item overrides). A subset of PREFIX_TO_CATEGORY.
     *
     * @var array<string, string>
     */
    private const PREFIX_TO_ITEM_TYPE = [
        'projects' => 'project',
        'pages' => 'page',
        'tasks' => 'task',
        'discussions' => 'discussion',
    ];

    public function __construct(private Security $security)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->security->getToken();
        if (!$token instanceof SwitchUserToken) {
            return; // Not an impersonated session — nothing to scope.
        }

        $target = $token->getUser();
        if (!$target instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $method = $request->getMethod();
        if ('OPTIONS' === $method) {
            return; // CORS preflight carries no credentials/intent.
        }
        $isRead = \in_array($method, ['GET', 'HEAD'], true);

        $segments = explode('/', ltrim($request->getPathInfo(), '/'));
        $first = $segments[0];
        $category = self::PREFIX_TO_CATEGORY[$first] ?? null;

        if (null === $category) {
            // Non-content endpoint: allow reads, deny every write.
            if (!$isRead) {
                throw new AccessDeniedHttpException(
                    'Writes outside granted content are not permitted during impersonation.',
                );
            }

            return;
        }

        $itemType = self::PREFIX_TO_ITEM_TYPE[$first] ?? null;
        $id = $segments[1] ?? null;

        if (null !== $itemType && is_string($id) && Uuid::isValid($id)) {
            // Item route (or a sub-resource of one): effective per-item level.
            $this->enforce($target->getImpersonationItemLevel($itemType, $id), $isRead, $category);

            return;
        }

        if (null !== $itemType) {
            // Collection of an addressable type. Reads are filtered row-by-row
            // by ImpersonationItemScope, so always allow them; writes (create)
            // need the category itself to be editable.
            if ($isRead || 'edit' === $target->getImpersonationLevel($category)) {
                return;
            }

            throw $this->denied($category);
        }

        // Non-addressable content category: wholesale category gate.
        $this->enforce($target->getImpersonationLevel($category), $isRead, $category);
    }

    private function enforce(string $level, bool $isRead, string $category): void
    {
        if ('edit' === $level) {
            return; // Read + write.
        }
        if ('view' === $level && $isRead) {
            return; // Read-only.
        }

        // 'none' (any method) or 'view' + write → denied.
        throw $this->denied($category);
    }

    private function denied(string $category): AccessDeniedHttpException
    {
        return new AccessDeniedHttpException(sprintf(
            'Your impersonation access to "%s" does not permit this action.',
            $category,
        ));
    }
}
