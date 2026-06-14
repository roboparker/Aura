<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convert the legacy `task.reminders` allow-list of offset strings
 * (`"15m"`, `"1h"`, `"1d"`) into the expanded reminder-object model
 * (#227 tasks redesign):
 *
 *   "15m" → {type:"relative", value:15, unit:"minutes", repeat:false}
 *   "1h"  → {type:"relative", value:1,  unit:"hours",   repeat:false}
 *   "1d"  → {type:"relative", value:1,  unit:"days",    repeat:false}
 *
 * The column is already `json`, so there's no schema change — this is a
 * pure data conversion (no-op when no rows have reminders). Unknown legacy
 * strings are dropped; a row that ends up with no reminders is set to NULL.
 */
final class Version20260613130000 extends AbstractMigration
{
    private const MAP = [
        '15m' => ['type' => 'relative', 'value' => 15, 'unit' => 'minutes', 'repeat' => false],
        '1h' => ['type' => 'relative', 'value' => 1, 'unit' => 'hours', 'repeat' => false],
        '1d' => ['type' => 'relative', 'value' => 1, 'unit' => 'days', 'repeat' => false],
    ];

    public function getDescription(): string
    {
        return 'Convert task.reminders offset strings to expanded reminder objects.';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, reminders FROM task WHERE reminders IS NOT NULL',
        );
        foreach ($rows as $row) {
            $raw = $row['reminders'];
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($decoded)) {
                continue;
            }

            $converted = [];
            foreach ($decoded as $entry) {
                if (is_array($entry)) {
                    // Already an object (re-run / mixed data) — keep as-is.
                    $converted[] = $entry;
                } elseif (is_string($entry) && isset(self::MAP[$entry])) {
                    $converted[] = self::MAP[$entry];
                }
            }

            $this->connection->executeStatement(
                'UPDATE task SET reminders = :reminders WHERE id = :id',
                [
                    'reminders' => [] === $converted ? null : json_encode($converted, JSON_THROW_ON_ERROR),
                    'id' => $row['id'],
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Best-effort reverse: relative reminders that map back to a legacy
        // offset become their string; everything else is dropped.
        $reverse = [
            'relative:15:minutes' => '15m',
            'relative:1:hours' => '1h',
            'relative:1:days' => '1d',
        ];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, reminders FROM task WHERE reminders IS NOT NULL',
        );
        foreach ($rows as $row) {
            $raw = $row['reminders'];
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($decoded)) {
                continue;
            }
            $offsets = [];
            foreach ($decoded as $entry) {
                if (!is_array($entry) || ($entry['type'] ?? null) !== 'relative') {
                    continue;
                }
                $key = sprintf('relative:%s:%s', $entry['value'] ?? '', $entry['unit'] ?? '');
                if (isset($reverse[$key])) {
                    $offsets[] = $reverse[$key];
                }
            }
            $this->connection->executeStatement(
                'UPDATE task SET reminders = :reminders WHERE id = :id',
                [
                    'reminders' => [] === $offsets ? null : json_encode($offsets, JSON_THROW_ON_ERROR),
                    'id' => $row['id'],
                ],
            );
        }
    }
}
