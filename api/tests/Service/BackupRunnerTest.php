<?php

namespace App\Tests\Service;

use App\Service\BackupRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests the retention policy (BackupRunner::prune) against a real
 * temp directory. The dump/archive halves need pg_dump + a database, so
 * they're exercised by the deploy pipeline itself rather than here.
 */
final class BackupRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/backup-runner-test-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir, 0750, true));
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        $leftovers = glob($this->dir . '/*');
        if (false !== $leftovers) {
            foreach ($leftovers as $file) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    public function testPruneKeepsNewestPerKindAndDeletesOldestFirst(): void
    {
        $dbFiles = $this->seed('db-%s.sql.gz', 7);
        $mediaFiles = $this->seed('media-%s.tar.gz', 7);
        // An unrelated file must never be touched by retention.
        $unrelated = $this->dir . '/notes.txt';
        file_put_contents($unrelated, 'keep me');

        $deleted = $this->runner(5)->prune();

        // The two oldest of each kind go, newest five stay.
        $expected = [...array_slice($dbFiles, 0, 2), ...array_slice($mediaFiles, 0, 2)];
        sort($expected, SORT_STRING);
        $actual = $deleted;
        sort($actual, SORT_STRING);
        $this->assertSame($expected, $actual);

        foreach ([...array_slice($dbFiles, 2), ...array_slice($mediaFiles, 2)] as $kept) {
            $this->assertFileExists($kept);
        }
        foreach ($expected as $gone) {
            $this->assertFileDoesNotExist($gone);
        }
        $this->assertFileExists($unrelated);
    }

    public function testPruneIsANoOpBelowTheCap(): void
    {
        $this->seed('db-%s.sql.gz', 3);

        $this->assertSame([], $this->runner(5)->prune());
        $files = glob($this->dir . '/db-*.sql.gz');
        $this->assertIsArray($files);
        $this->assertCount(3, $files);
    }

    public function testZeroCapDisablesPruning(): void
    {
        $this->seed('db-%s.sql.gz', 7);

        $this->assertSame([], $this->runner(0)->prune());
        $files = glob($this->dir . '/db-*.sql.gz');
        $this->assertIsArray($files);
        $this->assertCount(7, $files);
    }

    private function runner(int $maxBackups): BackupRunner
    {
        return new BackupRunner(
            $this->createMock(Connection::class),
            $this->dir,
            $this->dir . '/media',
            $maxBackups,
        );
    }

    /**
     * Create N files whose embedded timestamps ascend with the index, so
     * index 0 is the oldest. Returns the absolute paths in that order.
     *
     * @return list<string>
     */
    private function seed(string $template, int $count): array
    {
        $files = [];
        for ($i = 0; $i < $count; ++$i) {
            $stamp = sprintf('202606%02d-020000', $i + 1);
            $path = $this->dir . '/' . sprintf($template, $stamp);
            file_put_contents($path, 'x');
            $files[] = $path;
        }

        return $files;
    }
}
