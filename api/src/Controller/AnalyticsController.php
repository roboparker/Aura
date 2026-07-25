<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analytics\AnalyticsMetricInterface;
use App\Analytics\AnalyticsQuery;
use App\Analytics\AnalyticsRegistry;
use App\Entity\Space;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Analytics dashboard (#analytics) — `GET /spaces/{id}/analytics`.
 *
 * Returns one time series per metric over a date window. Extends the existing
 * reporting surface ({@see ReportController}) rather than starting a parallel
 * module: same space scoping, same 404-for-outsiders shape, same date parsing.
 *
 * **Permissions are per metric, not per endpoint.** Money metrics declare the
 * `invoices` category, which is {@see SpacePermission::ADMIN_RESERVED}, so they
 * must be resolved through `canByExplicitGrant()`. Using the ordinary `can()`
 * here would hand every member of every space the company's revenue, because
 * `can()` carries the "member with no assigned roles is unrestricted"
 * back-compat rule. Metrics the caller may not read are **omitted** rather than
 * erroring, so a mixed dashboard degrades to the parts they're entitled to.
 */
class AnalyticsController extends AbstractController
{
    /** Widest window we'll aggregate, in days (~3 years of months). */
    private const MAX_RANGE_DAYS = 1100;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private AnalyticsRegistry $registry,
        private SpacePermissionResolver $permissions,
    ) {
    }

    #[Route('/spaces/{id}/analytics', name: 'space_analytics', methods: ['GET'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        $space = $this->em->getRepository(Space::class)->find($id);
        // Existence-hiding 404 for outsiders, matching the access extensions.
        if (null === $space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        $interval = $request->query->getString('interval', 'month');
        if (!AnalyticsQuery::isValidInterval($interval)) {
            return $this->json(['error' => 'interval must be one of: day, week, month.'], 422);
        }

        // Parse before defaulting: a malformed date is `false`, which `??` would
        // happily pass through as a real value.
        $toInput = $this->parseDate($request->query->get('to'));
        $fromInput = $this->parseDate($request->query->get('from'));
        if (false === $toInput || false === $fromInput) {
            return $this->json(['error' => 'Dates must be formatted YYYY-MM-DD.'], 422);
        }

        $to = $toInput ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $from = $fromInput ?? $to->modify('-11 months')->modify('first day of this month');
        if ($to < $from) {
            return $this->json(['error' => 'The end of the range is before its start.'], 422);
        }
        // Each metric scans its table over the window, so an unbounded range is
        // a denial-of-service on ourselves.
        if ($from->diff($to)->days > self::MAX_RANGE_DAYS) {
            return $this->json(
                ['error' => sprintf('Range is too large — request at most %d days.', self::MAX_RANGE_DAYS)],
                422,
            );
        }

        $requested = $this->requestedKeys($request);
        if (null !== $requested) {
            $unknown = array_diff($requested, $this->registry->keys());
            if ([] !== $unknown) {
                return $this->json([
                    'error' => sprintf(
                        'Unknown metric(s): %s. Available: %s.',
                        implode(', ', $unknown),
                        implode(', ', $this->registry->keys()),
                    ),
                ], 422);
            }
        }

        $payload = [];
        foreach ($this->registry->all() as $metric) {
            if (null !== $requested && !in_array($metric->key(), $requested, true)) {
                continue;
            }
            if (!$this->allows($metric, $space, $user)) {
                continue;
            }

            $payload[] = [
                'key' => $metric->key(),
                'label' => $metric->label(),
                'unit' => $metric->unit(),
                'series' => $metric->series($this->db, $space, $from, $to, $interval),
            ];
        }

        return $this->json([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'interval' => $interval,
            'metrics' => $payload,
        ]);
    }

    /**
     * Whether the caller may read this metric in this space.
     *
     * Admin-reserved categories (`invoices`) need an *explicit* grant — space
     * admin, or a role that names the category. See the class docblock for why
     * plain `can()` is the wrong call here.
     */
    private function allows(AnalyticsMetricInterface $metric, Space $space, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN') || $space->isAdmin($user)) {
            return true;
        }

        $category = $metric->permissionCategory();
        if (in_array($category, SpacePermission::ADMIN_RESERVED, true)) {
            return $this->permissions->canByExplicitGrant($user, $space, $category, SpacePermission::READ);
        }

        return $this->permissions->can($user, $space, $category, SpacePermission::READ);
    }

    /**
     * The `?metrics=a,b` filter, or null for "everything I'm allowed to see".
     *
     * @return list<string>|null
     */
    private function requestedKeys(Request $request): ?array
    {
        $raw = $request->query->get('metrics');
        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $k): bool => '' !== $k));
    }

    private function parseDate(mixed $value): \DateTimeImmutable|false|null
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new \DateTimeZone('UTC'));
    }
}
