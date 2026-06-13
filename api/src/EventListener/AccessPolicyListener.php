<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\Access\AccessPolicy;
use App\Security\Access\ActorPolicyResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Enforces the current actor's {@see AccessPolicy} on REST requests. Actor-
 * agnostic: it asks {@see ActorPolicyResolver} for the active policy (admin
 * impersonation, scoped API token, or future agent) and no-ops for an
 * unconstrained session.
 *
 * Each request maps to an action by HTTP method (GET/HEAD = view, else =
 * edit) and to one of these shapes by path:
 *
 *  - Item route of an addressable type (`/projects/{id}` + sub-resources):
 *    governed by the EFFECTIVE item level (per-item override else category).
 *  - Collection of an addressable type (`/projects`, `/tasks`, …): reads are
 *    always allowed because AccessPolicyItemScope filters the rows to what's
 *    viewable; writes (create) require the category to be 'edit'.
 *  - Any other content category (`/comments`, `/notifications`, `/media-objects`):
 *    governed wholesale by the category level.
 *  - Non-content endpoints (app shell, `/api/me`, `/spaces`, `/groups`, auth):
 *    reads allowed so the UI loads + a session can be ended; ALL writes
 *    blocked, so nothing outside granted content can be mutated (including the
 *    actor's own preferences / token settings).
 *
 * Account-takeover actions (change password, disable 2FA, delete/export the
 * account) are independently protected by SensitiveActionVerifier's step-up.
 *
 * Runs after the firewall (priority 8) so the token/actor is resolved.
 */
#[AsEventListener(event: 'kernel.request', priority: 4)]
final class AccessPolicyListener
{
    /**
     * First path segment → content category. Anything not listed here is a
     * non-content endpoint (reads allowed, writes blocked).
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
     * First path segment → addressable item type (a subset of the above that
     * carries per-item overrides).
     *
     * @var array<string, string>
     */
    private const PREFIX_TO_ITEM_TYPE = [
        'projects' => 'project',
        'pages' => 'page',
        'tasks' => 'task',
        'discussions' => 'discussion',
    ];

    public function __construct(private ActorPolicyResolver $resolver)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $policy = $this->resolver->resolve();
        if (null === $policy) {
            return; // Unconstrained actor — nothing to enforce.
        }

        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/mcp')) {
            return; // MCP enforces per-tool via McpToolPolicy at dispatch.
        }
        $method = $request->getMethod();
        if ('OPTIONS' === $method) {
            return; // CORS preflight carries no intent.
        }
        $isRead = \in_array($method, ['GET', 'HEAD'], true);

        $segments = explode('/', ltrim($request->getPathInfo(), '/'));
        $first = $segments[0];
        $category = self::PREFIX_TO_CATEGORY[$first] ?? null;

        if (null === $category) {
            if (!$isRead) {
                throw new AccessDeniedHttpException(
                    'This action is not permitted for the current access scope.',
                );
            }

            return;
        }

        $itemType = self::PREFIX_TO_ITEM_TYPE[$first] ?? null;
        $id = $segments[1] ?? null;

        if (null !== $itemType && is_string($id) && Uuid::isValid($id)) {
            $this->enforce($policy->itemLevel($itemType, $id), $isRead, $category);

            return;
        }

        if (null !== $itemType) {
            // Collection of an addressable type: reads are row-filtered by
            // AccessPolicyItemScope, writes (create) need category edit.
            if ($isRead || AccessPolicy::EDIT === $policy->categoryLevel($category)) {
                return;
            }

            throw $this->denied($category);
        }

        $this->enforce($policy->categoryLevel($category), $isRead, $category);
    }

    private function enforce(string $level, bool $isRead, string $category): void
    {
        if (AccessPolicy::EDIT === $level) {
            return;
        }
        if (AccessPolicy::VIEW === $level && $isRead) {
            return;
        }

        throw $this->denied($category);
    }

    private function denied(string $category): AccessDeniedHttpException
    {
        return new AccessDeniedHttpException(sprintf(
            'Your access scope does not permit this action on "%s".',
            $category,
        ));
    }
}
