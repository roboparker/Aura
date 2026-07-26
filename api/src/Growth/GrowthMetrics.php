<?php

declare(strict_types=1);

namespace App\Growth;

use App\Entity\Subscription;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;

/**
 * Instance-wide acquisition metrics (#763): the signup → activation → paid →
 * churn funnel, plus a current-state snapshot.
 *
 * **First-party by design.** The obvious alternative was PostHog or Plausible,
 * but every number here is already a fact in our own database — a signup, a
 * first task, a subscription — not clickstream that needs a tracker. Shipping
 * user-level events to a third party to re-derive facts we already hold would
 * contradict the privacy posture that made Umami the choice for pageviews (see
 * docs/developer/analytics.md), and would put the launch's success metrics
 * behind someone else's retention window.
 *
 * Distinct from {@see \App\Analytics\AnalyticsRegistry}, which is per-space
 * business analytics for customers. This is instance-wide and admin-only: how
 * the *product* is doing, not how a customer's business is doing.
 *
 * Everything is set-based SQL. No per-user loops — these queries run over the
 * whole user table.
 */
final class GrowthMetrics
{
    /** Bucket sizes → the exact `date_trunc` unit. Never interpolated from input. */
    private const TRUNC = ['day' => 'day', 'week' => 'week', 'month' => 'month'];

    private const FORMAT = ['day' => 'YYYY-MM-DD', 'week' => 'IYYY-"W"IW', 'month' => 'YYYY-MM'];

    /**
     * Annual plans bill at 10× the monthly rate (two months free) — the
     * convention the pricing page implements. Monthly-equivalent revenue for an
     * annual subscription is therefore (monthly × 10) / 12.
     */
    private const ANNUAL_MONTHS_CHARGED = 10;

    public function __construct(
        private Connection $db,
        private int $proMonthlyCents,
        private int $businessMonthlyCents,
    ) {
    }

    public static function isValidInterval(string $interval): bool
    {
        return isset(self::TRUNC[$interval]);
    }

    /**
     * The funnel as four time series over the window.
     *
     * @return array<string, list<array{period: string, value: int}>>
     */
    public function funnel(\DateTimeImmutable $from, \DateTimeImmutable $to, string $interval): array
    {
        return [
            'signups' => $this->signups($from, $to, $interval),
            'activations' => $this->activations($from, $to, $interval),
            'upgrades' => $this->upgrades($from, $to, $interval),
            'churn' => $this->churn($from, $to, $interval),
        ];
    }

    /** @return list<array{period: string, value: int}> */
    private function signups(\DateTimeImmutable $from, \DateTimeImmutable $to, string $interval): array
    {
        return $this->bucketed(
            'SELECT %s AS period, COUNT(*) AS value
             FROM "user"
             WHERE created_at >= :from AND created_at < :to
             GROUP BY 1 ORDER BY 1',
            'created_at',
            $from,
            $to,
            $interval,
        );
    }

    /**
     * Activation = the first task a user ever created. Bucketed by *when that
     * first task happened*, not when they signed up, so the series answers
     * "how many people got going this week" rather than re-cutting signups.
     *
     * @return list<array{period: string, value: int}>
     */
    private function activations(\DateTimeImmutable $from, \DateTimeImmutable $to, string $interval): array
    {
        $trunc = self::TRUNC[$interval] ?? 'month';
        $format = self::FORMAT[$interval] ?? 'YYYY-MM';

        $sql = sprintf(
            'SELECT to_char(date_trunc(%s, first_task), %s) AS period, COUNT(*) AS value
             FROM (
                 SELECT owner_id, MIN(created_on) AS first_task
                 FROM task
                 WHERE owner_id IS NOT NULL
                 GROUP BY owner_id
             ) t
             WHERE first_task >= :from AND first_task < :to
             GROUP BY 1 ORDER BY 1',
            $this->db->quote($trunc),
            $this->db->quote($format),
        );

        return $this->rows($sql, ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
    }

    /**
     * A subscription reaching a paying state. Bucketed by creation, which is
     * when checkout completed — Stripe may flip status later, but the moment
     * that matters for acquisition is when they converted.
     *
     * @return list<array{period: string, value: int}>
     */
    private function upgrades(\DateTimeImmutable $from, \DateTimeImmutable $to, string $interval): array
    {
        $trunc = self::TRUNC[$interval] ?? 'month';
        $format = self::FORMAT[$interval] ?? 'YYYY-MM';

        $sql = sprintf(
            'SELECT to_char(date_trunc(%s, created_at), %s) AS period, COUNT(*) AS value
             FROM subscription
             WHERE created_at >= :from AND created_at < :to
               AND status IN (:entitling)
             GROUP BY 1 ORDER BY 1',
            $this->db->quote($trunc),
            $this->db->quote($format),
        );

        return $this->rows(
            $sql,
            [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'entitling' => Subscription::ENTITLING_STATUSES,
            ],
            ['entitling' => ArrayParameterType::STRING],
        );
    }

    /**
     * Cancellations, bucketed by when the row last changed.
     *
     * An approximation, and worth being honest about: we don't keep a status
     * history, so a subscription that cancels and is later edited again would
     * move buckets. Good enough to see a churn trend; not an audit trail.
     *
     * @return list<array{period: string, value: int}>
     */
    private function churn(\DateTimeImmutable $from, \DateTimeImmutable $to, string $interval): array
    {
        $trunc = self::TRUNC[$interval] ?? 'month';
        $format = self::FORMAT[$interval] ?? 'YYYY-MM';

        $sql = sprintf(
            'SELECT to_char(date_trunc(%s, updated_at), %s) AS period, COUNT(*) AS value
             FROM subscription
             WHERE updated_at >= :from AND updated_at < :to
               AND status IN (:ended)
             GROUP BY 1 ORDER BY 1',
            $this->db->quote($trunc),
            $this->db->quote($format),
        );

        return $this->rows(
            $sql,
            [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'ended' => [Subscription::STATUS_CANCELED, Subscription::STATUS_UNPAID],
            ],
            ['ended' => ArrayParameterType::STRING],
        );
    }

    /**
     * Current state: totals rather than a series.
     *
     * `mrrCents` is an **estimate** computed from list prices × seats, not from
     * Stripe. It ignores coupons, proration, tax, and failed charges. Stripe is
     * the source of truth for revenue; this is for watching a trend.
     *
     * @return array{users: int, waitlisted: int, activated: int, paidSubscriptions: int, paidSeats: int, mrrCents: int}
     */
    public function snapshot(): array
    {
        $users = $this->count('SELECT COUNT(*) FROM "user"');
        $waitlisted = $this->count('SELECT COUNT(*) FROM "user" WHERE waitlisted = true');
        $activated = $this->count('SELECT COUNT(DISTINCT owner_id) FROM task WHERE owner_id IS NOT NULL');

        /** @var list<array{plan: string, billing_interval: string|null, seats: int}> $subs */
        $subs = $this->db->fetchAllAssociative(
            'SELECT plan, billing_interval, seats FROM subscription WHERE status IN (:entitling)',
            ['entitling' => Subscription::ENTITLING_STATUSES],
            ['entitling' => ArrayParameterType::STRING],
        );

        $mrr = 0;
        $seats = 0;
        foreach ($subs as $sub) {
            $monthly = $this->monthlyCentsFor($sub['plan']);
            $subSeats = max(1, $sub['seats']);
            $seats += $subSeats;

            $mrr += 'year' === $sub['billing_interval']
                ? (int) round($monthly * self::ANNUAL_MONTHS_CHARGED / 12) * $subSeats
                : $monthly * $subSeats;
        }

        return [
            'users' => $users,
            'waitlisted' => $waitlisted,
            'activated' => $activated,
            'paidSubscriptions' => count($subs),
            'paidSeats' => $seats,
            'mrrCents' => $mrr,
        ];
    }

    /** `fetchOne` is typed mixed; COUNT(*) is always a numeric scalar. */
    private function count(string $sql): int
    {
        $value = $this->db->fetchOne($sql);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function monthlyCentsFor(string $plan): int
    {
        return match ($plan) {
            'business', 'team' => $this->businessMonthlyCents,
            default => $this->proMonthlyCents,
        };
    }

    /**
     * @return list<array{period: string, value: int}>
     */
    private function bucketed(
        string $template,
        string $dateColumn,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $interval,
    ): array {
        $trunc = self::TRUNC[$interval] ?? 'month';
        $format = self::FORMAT[$interval] ?? 'YYYY-MM';

        $sql = sprintf(
            $template,
            sprintf(
                'to_char(date_trunc(%s, %s), %s)',
                $this->db->quote($trunc),
                $dateColumn,
                $this->db->quote($format),
            ),
        );

        return $this->rows($sql, ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
    }

    /**
     * @param array<string, mixed>                                        $params
     * @param array<string, ArrayParameterType|ParameterType|Type|string> $types
     *
     * @return list<array{period: string, value: int}>
     */
    private function rows(string $sql, array $params, array $types = []): array
    {
        /** @var list<array{period: string, value: int|string}> $raw */
        $raw = $this->db->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => ['period' => $row['period'], 'value' => (int) $row['value']],
            $raw,
        );
    }
}
