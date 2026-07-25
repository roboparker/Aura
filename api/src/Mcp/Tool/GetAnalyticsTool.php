<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Analytics\AnalyticsMetricInterface;
use App\Analytics\AnalyticsQuery;
use App\Analytics\AnalyticsRegistry;
use App\Entity\Space;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Security\Access\AccessPolicy;
use App\Security\Access\ActorPolicyResolver;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Business analytics as time series — the MCP mirror of
 * {@see \App\Controller\AnalyticsController}.
 *
 * **Two gates, both narrowing.** A metric is returned only when the caller's
 * *space role* allows its category (money metrics ride the admin-reserved
 * `invoices` category, so they need an explicit grant) **and** the calling
 * token's own {@see AccessPolicy} covers that category. The second gate matters
 * because this one tool spans two categories: without it, a token deliberately
 * narrowed to `invoices` would still receive its owner's time metrics, quietly
 * widening the scope its owner chose. Denied metrics are omitted, not errored.
 */
final class GetAnalyticsTool implements McpToolInterface
{
    /** Widest window we'll aggregate, matching the REST endpoint. */
    private const MAX_RANGE_DAYS = 1100;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private AnalyticsRegistry $registry,
        private SpacePermissionResolver $permissions,
        private McpBillingAuthorization $authz,
        private ActorPolicyResolver $actorPolicy,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'get_analytics';
    }

    public function getDescription(): string
    {
        return 'Business metrics as time series: invoiced and collected revenue, tracked and billable hours, and unbilled work-in-progress value. Bucketed by day, week, or month over a date range. Use for trend questions ("how did revenue move this year", "am I tracking fewer hours than last quarter", "how much unbilled work is sitting there").';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'spaceId' => [
                    'type' => 'string',
                    'description' => 'Space to analyse. Defaults to the only space you can read, if there is exactly one.',
                ],
                'metrics' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $this->registry->keys()],
                    'description' => 'Limit to these metrics. Defaults to every metric you may read.',
                ],
                'from' => ['type' => 'string', 'description' => 'Start of the range, YYYY-MM-DD. Defaults to 12 months back.'],
                'to' => ['type' => 'string', 'description' => 'End of the range, YYYY-MM-DD. Defaults to today.'],
                'interval' => [
                    'type' => 'string',
                    'enum' => ['day', 'week', 'month'],
                    'description' => 'Bucket size. Defaults to month.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $space = $this->resolveSpace($arguments, $user);

        $interval = $this->input->optionalString($arguments, 'interval') ?? 'month';
        if (!AnalyticsQuery::isValidInterval($interval)) {
            throw McpException::invalidInput(
                sprintf('Unknown interval "%s". Expected one of: day, week, month.', $interval),
            );
        }

        $to = $this->parseDate($arguments, 'to') ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $from = $this->parseDate($arguments, 'from')
            ?? $to->modify('-11 months')->modify('first day of this month');

        if ($to < $from) {
            throw McpException::invalidInput('The end of the range is before its start.');
        }
        if ($from->diff($to)->days > self::MAX_RANGE_DAYS) {
            throw McpException::invalidInput(
                sprintf('Range is too large — request at most %d days.', self::MAX_RANGE_DAYS),
            );
        }

        $requested = $this->requestedMetrics($arguments);

        $items = [];
        foreach ($this->registry->all() as $metric) {
            if (null !== $requested && !in_array($metric->key(), $requested, true)) {
                continue;
            }
            if (!$this->allows($metric, $space, $user)) {
                continue;
            }

            $items[] = [
                'key' => $metric->key(),
                'label' => $metric->label(),
                'unit' => $metric->unit(),
                'series' => $metric->series($this->db, $space, $from, $to, $interval),
            ];
        }

        return [
            'space' => ['id' => (string) $space->getId(), 'name' => $space->getName()],
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'interval' => $interval,
            'metrics' => $items,
            // Money values are minor units (cents); time values are seconds.
            // Stated explicitly so a model doesn't report a 100x error.
            'valueUnits' => ['money' => 'minor currency units', 'seconds' => 'seconds'],
        ];
    }

    /**
     * Both gates: the caller's role in the space, then the calling token's own
     * scope. See the class docblock for why the second one is not redundant.
     */
    private function allows(AnalyticsMetricInterface $metric, Space $space, User $user): bool
    {
        $category = $metric->permissionCategory();

        $bySpaceRole = $space->isAdmin($user)
            || (in_array($category, SpacePermission::ADMIN_RESERVED, true)
                ? $this->permissions->canByExplicitGrant($user, $space, $category, SpacePermission::READ)
                : $this->permissions->can($user, $space, $category, SpacePermission::READ));

        if (!$bySpaceRole) {
            return false;
        }

        $policy = $this->actorPolicy->resolve();
        // No policy = an unrestricted actor; the space role was the only gate.
        return null === $policy || AccessPolicy::NONE !== $policy->categoryLevel($category);
    }

    /**
     * The space to analyse. Explicit id wins; otherwise we only guess when
     * there's exactly one candidate — silently picking one of several would
     * make the answer wrong in a way the model can't detect.
     *
     * @param array<string, mixed> $arguments
     */
    private function resolveSpace(array $arguments, User $user): Space
    {
        $spaceId = $this->input->optionalUuid('spaceId', $arguments['spaceId'] ?? null);

        if (null !== $spaceId) {
            $space = $this->em->getRepository(Space::class)->find($spaceId);
            // Existence-hiding, like every other space-scoped surface.
            if (null === $space || !$space->hasMember($user)) {
                throw McpException::notFound('No space with that id.');
            }

            return $space;
        }

        // Time is the broader of the two categories, so it yields the widest
        // candidate set; per-metric checks still run afterwards.
        $candidates = $this->authz->readableSpaces($user, SpacePermission::TIME_ENTRIES);
        if (1 === count($candidates)) {
            return $candidates[0];
        }
        if ([] === $candidates) {
            throw McpException::notFound('You have no spaces with analytics access.');
        }

        throw McpException::invalidInput(sprintf(
            'You belong to %d spaces — pass spaceId to choose one. Use list_spaces to see them.',
            count($candidates),
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>|null
     */
    private function requestedMetrics(array $arguments): ?array
    {
        $raw = $arguments['metrics'] ?? null;
        if (!is_array($raw) || [] === $raw) {
            return null;
        }

        $keys = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                throw McpException::invalidInput('metrics must be an array of metric keys.');
            }
            if (null === $this->registry->get($value)) {
                throw McpException::invalidInput(sprintf(
                    'Unknown metric "%s". Available: %s.',
                    $value,
                    implode(', ', $this->registry->keys()),
                ));
            }
            $keys[] = $value;
        }

        return $keys;
    }

    /** @param array<string, mixed> $arguments */
    private function parseDate(array $arguments, string $key): ?\DateTimeImmutable
    {
        $raw = $this->input->optionalString($arguments, $key);
        if (null === $raw) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'));
        if (false === $parsed) {
            throw McpException::invalidInput(sprintf('%s must be formatted YYYY-MM-DD.', $key));
        }

        return $parsed;
    }
}
