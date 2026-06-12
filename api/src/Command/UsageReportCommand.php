<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Per-user usage report over a recent window, read from the nightly
 * {@see \App\Entity\UserUsageSnapshot} rollup — the lens for eyeballing
 * relative cost when setting pricing / limits.
 *
 *   bin/console app:usage:report                 # last 30 days, top 25 by disk
 *   bin/console app:usage:report --days=7 --limit=50
 *
 * Disk + db are the latest snapshot in the window; calls are summed across it.
 */
#[AsCommand(
    name: 'app:usage:report',
    description: 'Show per-user disk, db, and call usage from the snapshot rollup.',
)]
final class UsageReportCommand extends Command
{
    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Window size in days.', '30')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max users to list.', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = max(1, (int) $input->getOption('days'));
        $limit = max(1, (int) $input->getOption('limit'));
        $since = (new \DateTimeImmutable(sprintf('-%d days', $days), new \DateTimeZone('UTC')))->format('Y-m-d');

        // $limit is a validated positive int (safe to inline); Postgres rejects
        // a string-bound LIMIT parameter, so we don't bind it.
        $sql = sprintf(
            <<<'SQL'
                WITH latest AS (
                    SELECT DISTINCT ON (user_id) user_id, disk_bytes, db_bytes_est
                    FROM user_usage_snapshot
                    WHERE captured_on >= :since
                    ORDER BY user_id, captured_on DESC
                ),
                calls AS (
                    SELECT user_id, SUM(api_calls) AS api, SUM(mcp_calls) AS mcp
                    FROM user_usage_snapshot
                    WHERE captured_on >= :since
                    GROUP BY user_id
                )
                SELECT u.email AS email, l.disk_bytes AS disk, l.db_bytes_est AS db,
                       c.api AS api, c.mcp AS mcp
                FROM latest l
                JOIN calls c ON c.user_id = l.user_id
                JOIN "user" u ON u.id = l.user_id
                ORDER BY l.disk_bytes DESC, c.api DESC
                LIMIT %d
                SQL,
            $limit,
        );
        $rows = $this->connection->executeQuery($sql, ['since' => $since])->fetchAllAssociative();

        if (0 === count($rows)) {
            $io->warning(sprintf('No usage snapshots in the last %d day(s). Has the snapshot job run yet?', $days));
            return Command::SUCCESS;
        }

        $table = [];
        foreach ($rows as $row) {
            $email = $row['email'];
            $table[] = [
                is_string($email) ? $email : '(unknown)',
                self::humanBytes($this->intOf($row['disk'])),
                self::humanBytes($this->intOf($row['db'])),
                number_format($this->intOf($row['api'])),
                number_format($this->intOf($row['mcp'])),
            ];
        }

        $io->title(sprintf('Per-user usage — last %d day(s)', $days));
        $io->table(['User', 'Disk', 'DB (est)', 'API calls', 'MCP calls'], $table);
        $io->note('Disk/DB are the most recent snapshot in the window; calls are summed over it. DB is a logical estimate.');

        return Command::SUCCESS;
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            ++$unit;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
