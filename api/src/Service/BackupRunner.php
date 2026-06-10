<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Application-managed backups: a compressed pg_dump of the database plus a
 * tar.gz of the uploaded media directory, written to timestamped files
 * with newest-N retention.
 *
 * This replaces the old scripts/backup.sh + host-cron pair. It runs in two
 * places, through the same code path:
 *  - nightly via the scheduler (App\Scheduler\MainScheduleProvider fires
 *    App\Message\RunBackup, handled on the worker), and
 *  - before every deploy (scripts/deploy.sh runs `bin/console
 *    app:backup:run` in a one-off container of the incoming image).
 *
 * pg_dump talks to the database over the network using the credentials of
 * the app's own Doctrine connection — no `docker compose exec` into the
 * database container. The backup dir is bind-mounted to ./backups on the
 * host in the compose stack, so scripts/restore.sh and off-box copy jobs
 * keep working on plain files.
 *
 * Both artifacts are written to a `.tmp` path and renamed only on success,
 * so a failed run never leaves a truncated file that looks like a valid
 * backup. Retention (`prune()`) keeps the newest N of each kind; filenames
 * embed a zero-padded UTC timestamp, so a lexicographic sort is
 * chronological and immune to mtime changes from copies or restores.
 */
final class BackupRunner
{
    private const DB_PATTERN = 'db-*.sql.gz';
    private const MEDIA_PATTERN = 'media-*.tar.gz';
    private const PROCESS_TIMEOUT_SECONDS = 900.0;

    public function __construct(
        private Connection $connection,
        #[Autowire('%app.backup_dir%')]
        private string $backupDir,
        #[Autowire('%kernel.project_dir%/var/media')]
        private string $mediaDir,
        #[Autowire('%app.backup_max_backups%')]
        private int $maxBackups,
    ) {
    }

    public function run(): BackupResult
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0750, true) && !is_dir($this->backupDir)) {
            throw new \RuntimeException(sprintf('Backup directory "%s" could not be created.', $this->backupDir));
        }

        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His');

        $dbFile = $this->dumpDatabase($timestamp);
        $mediaFile = is_dir($this->mediaDir) ? $this->archiveMedia($timestamp) : null;
        $pruned = $this->prune();

        return new BackupResult($dbFile, $mediaFile, $pruned);
    }

    /**
     * Keep only the newest N backups of each kind, oldest deleted first.
     * Public so the retention policy is unit-testable without a database.
     *
     * @return list<string> absolute paths of the files that were deleted
     */
    public function prune(): array
    {
        if ($this->maxBackups <= 0) {
            return [];
        }

        $deleted = [];
        foreach ([self::DB_PATTERN, self::MEDIA_PATTERN] as $pattern) {
            $files = glob($this->backupDir . '/' . $pattern);
            if (false === $files) {
                continue;
            }
            sort($files, SORT_STRING);
            $excess = count($files) - $this->maxBackups;
            for ($i = 0; $i < $excess; ++$i) {
                if (is_file($files[$i]) && unlink($files[$i])) {
                    $deleted[] = $files[$i];
                }
            }
        }

        return $deleted;
    }

    private function dumpDatabase(string $timestamp): string
    {
        $params = $this->connection->getParams();

        $file = sprintf('%s/db-%s.sql.gz', $this->backupDir, $timestamp);
        $tmp = $file . '.tmp';

        // Plain-format dump with built-in gzip compression: the output is
        // a compressed SQL script, replayable with `gunzip -c | psql`
        // (exactly what scripts/restore.sh does).
        $process = new Process(
            [
                'pg_dump',
                '--host', $this->stringParam($params['host'] ?? null, 'host'),
                '--port', $this->stringParam($params['port'] ?? null, 'port'),
                '--username', $this->stringParam($params['user'] ?? null, 'user'),
                '--dbname', $this->stringParam($params['dbname'] ?? null, 'dbname'),
                '--compress', '6',
                '--no-password',
                '--file', $tmp,
            ],
            null,
            ['PGPASSWORD' => $this->stringParam($params['password'] ?? null, 'password')],
            null,
            self::PROCESS_TIMEOUT_SECONDS,
        );
        $process->mustRun();

        $this->commit($tmp, $file);

        return $file;
    }

    private function archiveMedia(string $timestamp): string
    {
        $file = sprintf('%s/media-%s.tar.gz', $this->backupDir, $timestamp);
        $tmp = $file . '.tmp';

        // Archive with the same internal layout ("media/...") the old
        // shell script produced, so restore.sh's extraction is unchanged.
        $process = new Process(
            ['tar', '-czf', $tmp, '-C', dirname($this->mediaDir), basename($this->mediaDir)],
            null,
            null,
            null,
            self::PROCESS_TIMEOUT_SECONDS,
        );
        $process->mustRun();

        $this->commit($tmp, $file);

        return $file;
    }

    private function commit(string $tmp, string $file): void
    {
        if (!rename($tmp, $file)) {
            throw new \RuntimeException(sprintf('Could not move "%s" into place as "%s".', $tmp, $file));
        }
    }

    /**
     * Narrow one entry of Connection::getParams() to a CLI-safe string.
     */
    private function stringParam(mixed $value, string $key): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        throw new \RuntimeException(sprintf('Database connection parameter "%s" is missing — cannot run pg_dump.', $key));
    }
}
