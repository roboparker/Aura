<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * Scopes what an admin can do/see WHILE impersonating, per the target
 * user's `impersonationAccess` consent matrix.
 *
 * The master gate (may this admin impersonate this user at all?) is
 * App\Security\ImpersonationVoter. Once a session is impersonating — i.e.
 * carries a SwitchUserToken — this listener narrows it further:
 *
 *  - Requests are mapped to a content category by path prefix and to an
 *    action by HTTP method (GET/HEAD = view, anything else = edit).
 *  - The target's per-category level decides: 'none' blocks everything,
 *    'view' allows reads only, 'edit' allows reads + writes.
 *  - Endpoints that aren't a content category (the app shell, profile,
 *    spaces/groups structure, auth) allow reads so the impersonated UI can
 *    load and the admin can navigate + stop impersonating, but block ALL
 *    writes — so nothing outside an explicitly-granted content category can
 *    be mutated, including the target's own impersonation settings.
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

        $segment = explode('/', ltrim($request->getPathInfo(), '/'))[0];
        $category = self::PREFIX_TO_CATEGORY[$segment] ?? null;

        if (null === $category) {
            // Non-content endpoint: allow reads (app shell, /api/me, spaces
            // listing, the switch-out control), deny every write.
            if (!$isRead) {
                throw new AccessDeniedHttpException(
                    'Writes outside granted content are not permitted during impersonation.',
                );
            }

            return;
        }

        $level = $target->getImpersonationLevel($category);
        if ('edit' === $level) {
            return; // Full access to this category.
        }
        if ('view' === $level && $isRead) {
            return; // Read-only access.
        }

        // 'none' (any method) or 'view' + write → denied.
        throw new AccessDeniedHttpException(sprintf(
            'Your impersonation access to "%s" does not permit this action.',
            $category,
        ));
    }
}
