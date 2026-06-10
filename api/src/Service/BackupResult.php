<?php

namespace App\Service;

/**
 * Outcome of one App\Service\BackupRunner::run() pass.
 *
 * $mediaFile is null when the media directory doesn't exist (e.g. a dev
 * checkout that has never received an upload) — the database dump alone
 * still counts as a successful backup.
 */
final class BackupResult
{
    /**
     * @param list<string> $pruned absolute paths deleted by retention
     */
    public function __construct(
        public readonly string $dbFile,
        public readonly ?string $mediaFile,
        public readonly array $pruned,
    ) {
    }
}
