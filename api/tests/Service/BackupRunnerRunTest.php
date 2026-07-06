<?php

namespace App\Tests\Service;

use App\Service\BackupRunner;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The dump/archive half of {@see BackupRunner}, run for real against the test
 * database (pg_dump + the DB are in the container).
 */
class BackupRunnerRunTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir() . '/backup-run-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->glob('/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @unlink($this->dir . '/media-src/note.txt');
        @rmdir($this->dir . '/media-src');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return list<string> */
    private function glob(string $suffix): array
    {
        $found = glob($this->dir . $suffix);

        return false === $found ? [] : $found;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine')->getConnection();
        assert($connection instanceof Connection);

        return $connection;
    }

    public function testRunDumpsDatabaseAndSkipsMissingMedia(): void
    {
        $runner = new BackupRunner($this->connection(), $this->dir, $this->dir . '/absent-media', 5);

        $result = $runner->run();

        $this->assertNotEmpty($this->glob('/db-*.sql.gz'), 'a compressed dump is written');
        $this->assertNull($result->mediaFile, 'no media dir → no archive');
    }

    public function testRunArchivesMediaWhenPresent(): void
    {
        $media = $this->dir . '/media-src';
        mkdir($media, 0750, true);
        file_put_contents($media . '/note.txt', 'hello');

        $runner = new BackupRunner($this->connection(), $this->dir, $media, 5);
        $result = $runner->run();

        $this->assertNotEmpty($this->glob('/media-*.tar.gz'), 'media is archived');
        $this->assertNotNull($result->mediaFile);
    }
}
