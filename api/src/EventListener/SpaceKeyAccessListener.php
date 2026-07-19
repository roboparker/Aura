<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\Permission\ActorContext;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gates a space-scoped API key (#space-roles) by its roles' CRUD permissions,
 * BEFORE API Platform evaluates entity `security:` expressions — so the
 * creator's own owner/admin bypasses (the key authenticates as its creator)
 * can never leak. Maps the request's first path segment to a content category
 * and the HTTP method to a CRUD action; if the key's roles don't grant it
 * (or the path isn't a content surface at all), it's 403'd here.
 *
 * Space CONFINEMENT (the key only touches its own space's rows/items) is
 * enforced by the access extensions + the create-time voter, not here.
 * No-op for everything except space-scoped keys.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final class SpaceKeyAccessListener
{
    /** First path segment → permission category. Anything else is denied. */
    private const PREFIX_TO_CATEGORY = [
        'boards' => SpacePermission::BOARDS,
        'tasks' => SpacePermission::TASKS,
        'pages' => SpacePermission::PAGES,
        'comments' => SpacePermission::COMMENTS,
        'custom_field_definitions' => SpacePermission::CUSTOM_FIELDS,
        'custom_field_values' => SpacePermission::CUSTOM_FIELDS,
        'tags' => SpacePermission::TAGS,
        'groups' => SpacePermission::GROUPS,
        'task_sections' => SpacePermission::BOARDS,
        'task_relationships' => SpacePermission::TASKS,
        'media-objects' => SpacePermission::FILES,
        'media_objects' => SpacePermission::FILES,
        'media' => SpacePermission::FILES,
    ];

    public function __construct(
        private readonly ActorContext $actor,
        private readonly SpacePermissionResolver $resolver,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $key = $this->actor->scopedKey();
        if (null === $key) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        // MCP enforces scoped keys itself at tool dispatch.
        if (str_starts_with($path, '/mcp')) {
            return;
        }

        $method = strtoupper($request->getMethod());
        if ('OPTIONS' === $method) {
            return; // CORS preflight carries no credentials/intent
        }

        $segment = explode('/', ltrim($path, '/'))[0];
        $category = self::PREFIX_TO_CATEGORY[$segment] ?? null;
        if (null === $category) {
            throw new AccessDeniedHttpException('This API key is limited to space content.');
        }

        $action = match ($method) {
            'GET', 'HEAD' => SpacePermission::READ,
            'POST' => SpacePermission::CREATE,
            'PUT', 'PATCH' => SpacePermission::UPDATE,
            'DELETE' => SpacePermission::DELETE,
            default => SpacePermission::UPDATE,
        };

        if (!$this->resolver->keyAllowsCategory($key, $category, $action)) {
            throw new AccessDeniedHttpException(
                sprintf("This API key's roles don't allow %s on %s.", $action, $category),
            );
        }
    }
}
