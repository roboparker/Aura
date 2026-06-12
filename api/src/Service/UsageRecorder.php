<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Increments the live per-user usage counters ({@see \App\Entity\UserUsageCounter}).
 *
 * Every call is a single narrow UPSERT into a (user, UTC day, metric) bucket,
 * so high-frequency events (API requests, MCP tool calls) never spawn one row
 * each — the table stays at users × days × metrics. Writes are wrapped in a
 * catch-all: usage accounting must never break or slow a user request, so a
 * failed increment is logged and swallowed rather than propagated.
 *
 * Callers keep this off the request latency path themselves: the HTTP API
 * counter fires from {@see \App\EventListener\UsageTrackingListener} at
 * kernel.terminate (after the response is flushed), and the MCP counter fires
 * right after a tool dispatches in {@see \App\Controller\McpController}.
 */
final class UsageRecorder
{
    public const METRIC_API_CALL = 'api_call';
    public const METRIC_MCP_CALL = 'mcp_call';

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function recordApiCall(User $user): void
    {
        $this->increment($user, self::METRIC_API_CALL);
    }

    public function recordMcpCall(User $user): void
    {
        $this->increment($user, self::METRIC_MCP_CALL);
    }

    private function increment(User $user, string $metric): void
    {
        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        try {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO user_usage_counter (id, user_id, day, metric, count)
                    VALUES (:id, :userId, :day, :metric, 1)
                    ON CONFLICT (user_id, day, metric)
                    DO UPDATE SET count = user_usage_counter.count + 1
                    SQL,
                [
                    'id' => Uuid::v4()->toRfc4122(),
                    'userId' => $userId->toRfc4122(),
                    'day' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
                    'metric' => $metric,
                ],
            );
        } catch (\Throwable $e) {
            // Never let usage tracking break the request it's measuring.
            $this->logger->warning('Usage counter increment failed', [
                'metric' => $metric,
                'exception' => $e,
            ]);
        }
    }
}
