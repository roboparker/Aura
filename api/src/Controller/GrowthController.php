<?php

declare(strict_types=1);

namespace App\Controller;

use App\Growth\GrowthMetrics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /admin/growth` — the acquisition funnel and current-state snapshot
 * (#763).
 *
 * Instance-wide and admin-only: this is how the *product* is doing, not how a
 * customer's business is doing. Gated in `security.yaml` on `^/admin/growth`
 * rather than by an expression here, matching the other admin surfaces.
 */
class GrowthController extends AbstractController
{
    /** ~3 years of daily buckets. Guards against an unbounded scan. */
    private const MAX_RANGE_DAYS = 1100;

    public function __construct(
        private GrowthMetrics $metrics,
    ) {
    }

    #[Route('/admin/growth', name: 'admin_growth', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $interval = $request->query->getString('interval', 'week');
        if (!GrowthMetrics::isValidInterval($interval)) {
            return $this->json(['error' => 'interval must be one of: day, week, month.'], 422);
        }

        // Parse before defaulting: a malformed date parses to false, which `??`
        // would pass through as a real value.
        $toInput = $this->parseDate($request->query->get('to'));
        $fromInput = $this->parseDate($request->query->get('from'));
        if (false === $toInput || false === $fromInput) {
            return $this->json(['error' => 'Dates must be formatted YYYY-MM-DD.'], 422);
        }

        // `to` is exclusive in the queries, so default it to tomorrow — an
        // inclusive-looking "to=today" would otherwise drop today's signups.
        $to = ($toInput ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('+1 day');
        $from = $fromInput ?? $to->modify('-12 weeks');

        if ($to <= $from) {
            return $this->json(['error' => 'The end of the range is before its start.'], 422);
        }
        if ($from->diff($to)->days > self::MAX_RANGE_DAYS) {
            return $this->json(
                ['error' => sprintf('Range is too large — request at most %d days.', self::MAX_RANGE_DAYS)],
                422,
            );
        }

        return $this->json([
            'from' => $from->format('Y-m-d'),
            // Report the inclusive end, which is what the caller asked for.
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'interval' => $interval,
            'funnel' => $this->metrics->funnel($from, $to, $interval),
            'snapshot' => $this->metrics->snapshot(),
            // Stated on the wire so a reader can't mistake the estimate for
            // billed revenue.
            'notes' => [
                'mrr' => 'Estimated from list prices x seats. Stripe is the source of truth for revenue.',
                'activation' => 'A user is activated the first time they create a task.',
            ],
        ]);
    }

    private function parseDate(mixed $value): \DateTimeImmutable|false|null
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new \DateTimeZone('UTC'));
    }
}
