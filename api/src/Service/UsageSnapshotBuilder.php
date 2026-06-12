<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the nightly per-user usage rollup — the {@see \App\Entity\UserUsageSnapshot}
 * rows queried for cost estimation / pricing.
 *
 * For a given day it computes three things per user, entirely with set-based
 * SQL (no per-user N+1, never touches a live request):
 *  - disk bytes: SUM(media_object.byte_size) owned by the user;
 *  - db bytes (estimate): SUM(octet_length(...)) over the big text columns the
 *    user owns across the content tables, plus a fixed per-row overhead — a
 *    logical-size proxy, not physical page bytes (exact per-user physical size
 *    isn't cheaply attributable in Postgres);
 *  - api/mcp call counts: that day's {@see \App\Entity\UserUsageCounter} buckets.
 *
 * Sizes are point-in-time at capture; the nightly run lands a few hours after
 * the day it labels, which is fine for these slow-moving aggregates.
 */
final class UsageSnapshotBuilder
{
    /**
     * Content tables contributing to the db-size estimate: the owning-user FK
     * column and the large text columns to measure. Identifiers are constants
     * (never user input), so they're safe to interpolate into SQL.
     *
     * @var list<array{table: string, owner: string, text: list<string>}>
     */
    private const CONTENT_TABLES = [
        ['table' => 'task', 'owner' => 'owner_id', 'text' => ['title', 'description']],
        ['table' => 'project', 'owner' => 'owner_id', 'text' => ['title', 'description']],
        ['table' => 'page', 'owner' => 'created_by_id', 'text' => ['title', 'body']],
        ['table' => 'comment', 'owner' => 'author_id', 'text' => ['body']],
        ['table' => 'discussion', 'owner' => 'author_id', 'text' => ['title', 'body']],
    ];

    public function __construct(
        private Connection $connection,
        #[Autowire('%app.usage_db_row_overhead_bytes%')]
        private int $rowOverheadBytes,
    ) {
    }

    /**
     * Capture a snapshot for the given day (defaults to yesterday, UTC).
     * Upserts one row per user that has any disk, db, or call usage. Returns
     * the number of users written.
     */
    public function capture(?\DateTimeImmutable $day = null): int
    {
        $day ??= new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC'));
        $dayStr = $day->format('Y-m-d');

        $disk = $this->fetchByteMap('SELECT owner_id::text AS uid, COALESCE(SUM(byte_size), 0) AS bytes '
            . 'FROM media_object GROUP BY owner_id');
        $db = $this->fetchByteMap($this->dbEstimateSql());
        [$api, $mcp] = $this->fetchCallMaps($dayStr);

        /** @var array<string, true> $userIds */
        $userIds = [];
        foreach ([$disk, $db, $api, $mcp] as $map) {
            foreach (array_keys($map) as $uid) {
                $userIds[$uid] = true;
            }
        }

        if (0 === count($userIds)) {
            return 0;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $written = 0;

        $this->connection->beginTransaction();
        try {
            foreach (array_keys($userIds) as $uid) {
                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO user_usage_snapshot
                            (id, user_id, captured_on, disk_bytes, db_bytes_est, api_calls, mcp_calls, created_at)
                        VALUES (:id, :uid, :day, :disk, :db, :api, :mcp, :now)
                        ON CONFLICT (user_id, captured_on) DO UPDATE SET
                            disk_bytes = EXCLUDED.disk_bytes,
                            db_bytes_est = EXCLUDED.db_bytes_est,
                            api_calls = EXCLUDED.api_calls,
                            mcp_calls = EXCLUDED.mcp_calls,
                            created_at = EXCLUDED.created_at
                        SQL,
                    [
                        'id' => Uuid::v4()->toRfc4122(),
                        'uid' => $uid,
                        'day' => $dayStr,
                        'disk' => $disk[$uid] ?? 0,
                        'db' => $db[$uid] ?? 0,
                        'api' => $api[$uid] ?? 0,
                        'mcp' => $mcp[$uid] ?? 0,
                        'now' => $now,
                    ],
                );
                ++$written;
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $written;
    }

    /**
     * Build the db-size estimate query: a UNION ALL of per-table
     * `SUM(octet_length(col) + ... + overhead)` grouped by owner, then summed
     * per user across tables.
     */
    private function dbEstimateSql(): string
    {
        $parts = [];
        foreach (self::CONTENT_TABLES as $t) {
            $terms = [];
            foreach ($t['text'] as $col) {
                $terms[] = sprintf("octet_length(coalesce(%s, ''))", $col);
            }
            $terms[] = (string) max(0, $this->rowOverheadBytes);
            $parts[] = sprintf(
                'SELECT %1$s::text AS uid, SUM(%2$s) AS bytes FROM %3$s WHERE %1$s IS NOT NULL GROUP BY %1$s',
                $t['owner'],
                implode(' + ', $terms),
                $t['table'],
            );
        }

        return 'SELECT uid, SUM(bytes) AS bytes FROM (' . implode(' UNION ALL ', $parts) . ') s GROUP BY uid';
    }

    /**
     * Run a `uid, bytes` query and fold it into a uid => int map.
     *
     * @return array<string, int>
     */
    private function fetchByteMap(string $sql): array
    {
        /** @var array<string, int> $map */
        $map = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllAssociative() as $row) {
            $uid = $row['uid'];
            $bytes = $row['bytes'];
            if (!is_string($uid)) {
                continue;
            }
            $map[$uid] = is_numeric($bytes) ? (int) $bytes : 0;
        }

        return $map;
    }

    /**
     * Read the day's call counters, split into api and mcp uid => count maps.
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function fetchCallMaps(string $dayStr): array
    {
        /** @var array<string, int> $api */
        $api = [];
        /** @var array<string, int> $mcp */
        $mcp = [];

        $rows = $this->connection->executeQuery(
            'SELECT user_id::text AS uid, metric, count FROM user_usage_counter WHERE day = :day',
            ['day' => $dayStr],
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            $uid = $row['uid'];
            $metric = $row['metric'];
            $count = $row['count'];
            if (!is_string($uid) || !is_string($metric)) {
                continue;
            }
            $value = is_numeric($count) ? (int) $count : 0;
            if (UsageRecorder::METRIC_API_CALL === $metric) {
                $api[$uid] = $value;
            } elseif (UsageRecorder::METRIC_MCP_CALL === $metric) {
                $mcp[$uid] = $value;
            }
        }

        return [$api, $mcp];
    }
}
