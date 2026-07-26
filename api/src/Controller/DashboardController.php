<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dashboard\WidgetAccess;
use App\Dashboard\WidgetDefinitionInterface;
use App\Dashboard\WidgetRegistry;
use App\Entity\DashboardWidget;
use App\Entity\Space;
use App\Entity\User;
use App\Repository\DashboardWidgetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * The configurable space dashboard (#759).
 *
 * Reads are open to any space member but **filtered per widget** — a widget
 * whose category the caller can't read is omitted, so a member without
 * invoicing access sees a shorter dashboard rather than a broken one. Writes
 * are space-admin only, because the layout is shared: one member rearranging
 * would change what everyone sees.
 *
 * Widget data is served per instance rather than resolved into the layout
 * response, so widgets load in parallel and one slow query can't hold up the
 * page — and a new widget type needs no new route.
 */
class DashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private DashboardWidgetRepository $widgets,
        private WidgetRegistry $registry,
        private WidgetAccess $access,
    ) {
    }

    /** The space's layout: every widget the caller is allowed to see. */
    #[Route('/spaces/{id}/dashboard', name: 'space_dashboard', methods: ['GET'])]
    public function show(string $id, #[CurrentUser] ?User $user): Response
    {
        [$space, $error] = $this->resolveSpace($id, $user);
        if (null !== $error) {
            return $error;
        }
        assert($space instanceof Space && $user instanceof User);

        $items = [];
        foreach ($this->widgets->forSpace($space) as $widget) {
            $definition = $this->registry->get($widget->getType());

            // An unregistered type is a widget whose module is gone or not yet
            // deployed. Surface it as a placeholder rather than dropping it —
            // silently vanishing would look like data loss to an admin, and
            // deleting it would make a rollback lossy.
            if (null === $definition) {
                $items[] = $this->serialize($widget, null);
                continue;
            }
            // Config-aware: what this instance was set to show decides who may
            // see it, not just its type.
            if (!$this->access->canRead($definition, $space, $user, $widget->getConfig())) {
                continue;
            }

            $items[] = $this->serialize($widget, $definition);
        }

        return $this->json([
            'space' => (string) $space->getId(),
            'canEdit' => $this->access->canEditLayout($space, $user),
            'widgets' => $items,
        ]);
    }

    /** Addable widget types, already filtered to what the caller can read. */
    #[Route('/spaces/{id}/dashboard/catalog', name: 'space_dashboard_catalog', methods: ['GET'])]
    public function catalog(string $id, #[CurrentUser] ?User $user): Response
    {
        [$space, $error] = $this->resolveSpace($id, $user);
        if (null !== $error) {
            return $error;
        }
        assert($space instanceof Space && $user instanceof User);

        $available = $this->access->filterReadable($this->registry->all(), $space, $user);

        return $this->json([
            'widgets' => array_map(
                fn (WidgetDefinitionInterface $d) => [
                    'type' => $d->type(),
                    'name' => $d->name(),
                    'description' => $d->description(),
                    'group' => $d->group(),
                    'defaultSize' => $d->defaultSize(),
                    'configSchema' => $d->configSchema(),
                ],
                $available,
            ),
        ]);
    }

    #[Route('/spaces/{id}/dashboard/widgets', name: 'space_dashboard_add', methods: ['POST'])]
    public function add(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        [$space, $error] = $this->resolveSpace($id, $user);
        if (null !== $error) {
            return $error;
        }
        assert($space instanceof Space && $user instanceof User);

        if (!$this->access->canEditLayout($space, $user)) {
            return $this->json(['error' => 'Only space admins can change the dashboard.'], 403);
        }
        if ($this->widgets->countForSpace($space) >= DashboardWidget::MAX_PER_SPACE) {
            return $this->json([
                'error' => sprintf('A dashboard can hold at most %d widgets.', DashboardWidget::MAX_PER_SPACE),
            ], 422);
        }

        $payload = $request->toArray();
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $definition = $this->registry->get($type);
        if (null === $definition) {
            return $this->json(['error' => sprintf('Unknown widget type "%s".', $type)], 422);
        }

        $config = $this->configFrom($payload);
        $message = $definition->validateConfig($config);
        if (null !== $message) {
            return $this->json(['error' => $message], 422);
        }

        // Adding a widget you can't read would put a permanently blank box on
        // everyone's dashboard, so the read gate applies to writes too. Checked
        // against the submitted config, since that can decide the category.
        if (!$this->access->canRead($definition, $space, $user, $config)) {
            return $this->json(['error' => 'You do not have access to that widget.'], 403);
        }

        $size = $definition->defaultSize();
        $widget = (new DashboardWidget())
            ->setSpace($space)
            ->setType($type)
            ->setConfig($config)
            ->setPosition($this->widgets->nextPosition($space))
            ->setWidth($size['w'])
            ->setHeight($size['h'])
            ->setCreatedBy($user);

        $this->em->persist($widget);
        $this->em->flush();

        return $this->json($this->serialize($widget, $definition), 201);
    }

    /** Reconfigure or resize one widget. */
    #[Route('/dashboard-widgets/{widgetId}', name: 'dashboard_widget_update', methods: ['PATCH'])]
    public function update(string $widgetId, Request $request, #[CurrentUser] ?User $user): Response
    {
        [$widget, $error] = $this->resolveWidget($widgetId, $user, requireEdit: true);
        if (null !== $error) {
            return $error;
        }
        assert($widget instanceof DashboardWidget);

        $definition = $this->registry->get($widget->getType());
        $payload = $request->toArray();

        if (array_key_exists('config', $payload)) {
            $config = $this->configFrom($payload);
            // No definition = an unregistered type. Refuse to edit its config
            // rather than validating nothing and storing whatever arrived.
            if (null === $definition) {
                return $this->json(['error' => 'This widget type is not available on this server.'], 422);
            }
            $message = $definition->validateConfig($config);
            if (null !== $message) {
                return $this->json(['error' => $message], 422);
            }
            $widget->setConfig($config);
        }

        if (is_int($payload['width'] ?? null)) {
            $widget->setWidth($payload['width']);
        }
        if (is_int($payload['height'] ?? null)) {
            $widget->setHeight($payload['height']);
        }

        $this->em->flush();

        return $this->json($this->serialize($widget, $definition));
    }

    #[Route('/dashboard-widgets/{widgetId}', name: 'dashboard_widget_delete', methods: ['DELETE'])]
    public function remove(string $widgetId, #[CurrentUser] ?User $user): Response
    {
        [$widget, $error] = $this->resolveWidget($widgetId, $user, requireEdit: true);
        if (null !== $error) {
            return $error;
        }
        assert($widget instanceof DashboardWidget);

        $this->em->remove($widget);
        $this->em->flush();

        return new Response(null, 204);
    }

    /**
     * Rewrites the layout order from a list of widget ids.
     *
     * Takes the whole order at once because a drag moves one widget and
     * renumbers its neighbours — sending that as N separate position patches
     * would leave the layout scrambled if one failed.
     */
    #[Route('/spaces/{id}/dashboard/reorder', name: 'space_dashboard_reorder', methods: ['POST'])]
    public function reorder(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        [$space, $error] = $this->resolveSpace($id, $user);
        if (null !== $error) {
            return $error;
        }
        assert($space instanceof Space && $user instanceof User);

        if (!$this->access->canEditLayout($space, $user)) {
            return $this->json(['error' => 'Only space admins can change the dashboard.'], 403);
        }

        $payload = $request->toArray();
        $order = $payload['order'] ?? null;
        if (!is_array($order)) {
            return $this->json(['error' => 'order must be an array of widget ids.'], 422);
        }

        // Index the space's own widgets, so an id from another space simply
        // isn't found rather than being reordered across the boundary.
        $byId = [];
        foreach ($this->widgets->forSpace($space) as $widget) {
            $byId[(string) $widget->getId()] = $widget;
        }

        $position = 0;
        foreach ($order as $rawId) {
            if (!is_string($rawId) || !isset($byId[$rawId])) {
                continue;
            }
            $byId[$rawId]->setPosition($position++);
            unset($byId[$rawId]);
        }
        // Anything the client didn't mention keeps its relative order at the end.
        foreach ($byId as $widget) {
            $widget->setPosition($position++);
        }

        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * One widget's payload. The permission check runs here as well as in
     * {@see show()} — this route is directly addressable, so it can't lean on
     * having been filtered out of a listing.
     */
    #[Route('/dashboard-widgets/{widgetId}/data', name: 'dashboard_widget_data', methods: ['GET'])]
    public function data(string $widgetId, #[CurrentUser] ?User $user): Response
    {
        [$widget, $error] = $this->resolveWidget($widgetId, $user, requireEdit: false);
        if (null !== $error) {
            return $error;
        }
        assert($widget instanceof DashboardWidget && $user instanceof User);

        $definition = $this->registry->get($widget->getType());
        if (null === $definition) {
            return $this->json(['error' => 'This widget type is not available on this server.'], 404);
        }

        $space = $widget->getSpace();
        assert($space instanceof Space);
        if (!$this->access->canRead($definition, $space, $user, $widget->getConfig())) {
            return $this->json(['error' => 'Widget not found.'], 404);
        }

        return $this->json(['data' => $definition->data($space, $widget->getConfig(), $user)]);
    }

    /**
     * @return array{0: Space|null, 1: Response|null}
     */
    private function resolveSpace(string $id, ?User $user): array
    {
        if (null === $user) {
            return [null, $this->json(['error' => 'Not authenticated.'], 401)];
        }
        if (!Uuid::isValid($id)) {
            return [null, $this->json(['error' => 'Space not found.'], 404)];
        }

        $space = $this->em->getRepository(Space::class)->find($id);
        // Existence-hiding 404, matching the access extensions.
        if (null === $space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return [null, $this->json(['error' => 'Space not found.'], 404)];
        }

        return [$space, null];
    }

    /**
     * @return array{0: DashboardWidget|null, 1: Response|null}
     */
    private function resolveWidget(string $widgetId, ?User $user, bool $requireEdit): array
    {
        if (null === $user) {
            return [null, $this->json(['error' => 'Not authenticated.'], 401)];
        }
        if (!Uuid::isValid($widgetId)) {
            return [null, $this->json(['error' => 'Widget not found.'], 404)];
        }

        $widget = $this->em->getRepository(DashboardWidget::class)->find($widgetId);
        $space = $widget?->getSpace();
        if (null === $widget || null === $space || !$space->hasMember($user)) {
            return [null, $this->json(['error' => 'Widget not found.'], 404)];
        }

        if ($requireEdit && !$this->access->canEditLayout($space, $user)) {
            return [null, $this->json(['error' => 'Only space admins can change the dashboard.'], 403)];
        }

        return [$widget, null];
    }

    /**
     * @param array<int|string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function configFrom(array $payload): array
    {
        $config = $payload['config'] ?? [];

        /** @var array<string, mixed> $config */
        $config = is_array($config) ? $config : [];

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(DashboardWidget $widget, ?WidgetDefinitionInterface $definition): array
    {
        return [
            'id' => (string) $widget->getId(),
            'type' => $widget->getType(),
            // Null for an unregistered type — the client renders a placeholder
            // captioned with the raw type rather than crashing on a missing name.
            'name' => $definition?->name(),
            'available' => null !== $definition,
            'config' => $widget->getConfig(),
            'configSchema' => $definition?->configSchema() ?? [],
            'width' => $widget->getWidth(),
            'height' => $widget->getHeight(),
            'position' => $widget->getPosition(),
        ];
    }
}
