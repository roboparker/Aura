<?php

namespace App\Service;

use App\Entity\AccountExport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes account exports past their retention window: the zip on disk and
 * the account_export row. Runs nightly via the scheduler
 * (App\Message\PruneAccountExports) and on demand through
 * `bin/console app:account-exports:prune`. Mirrors {@see SpaceExportPruner}.
 *
 * Two reaping rules:
 *  - completed exports whose `expiresAt` has passed (the advertised
 *    "link valid for N days" lifetime), and
 *  - rows that never completed (pending/processing/failed with no expiry)
 *    once they age past the same retention window — covers exports whose
 *    job exhausted its retries or whose worker died mid-build.
 *
 * A final filesystem sweep removes orphaned archives (e.g. the row was
 * CASCADE-deleted with its user, or a crashed build left a `.tmp`) whose
 * mtime is older than the retention window.
 */
final class AccountExportPruner
{
    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%app.account_export_dir%')]
        private string $exportDir,
        #[Autowire('%app.account_export_retention_days%')]
        private int $retentionDays,
    ) {
    }

    /**
     * @return int number of export rows deleted
     */
    public function prune(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $cutoff = $now->sub(new \DateInterval(sprintf('P%dD', $this->retentionDays)));

        $expired = $this->em->getRepository(AccountExport::class)
            ->createQueryBuilder('e')
            ->where('e.expiresAt <= :now OR (e.expiresAt IS NULL AND e.createdAt <= :cutoff)')
            ->setParameter('now', $now)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        /** @var AccountExport $export */
        foreach ($expired as $export) {
            $path = $export->getFilePath();
            if (null !== $path && is_file($path)) {
                unlink($path);
            }
            $this->em->remove($export);
        }
        $this->em->flush();

        $this->sweepOrphanedFiles($cutoff);

        return count($expired);
    }

    private function sweepOrphanedFiles(\DateTimeImmutable $cutoff): void
    {
        foreach (['/account-export-*.zip', '/account-export-*.zip.tmp'] as $pattern) {
            $files = glob($this->exportDir . $pattern);
            if (false === $files) {
                continue;
            }
            foreach ($files as $file) {
                $mtime = filemtime($file);
                if (is_int($mtime) && $mtime <= $cutoff->getTimestamp()) {
                    unlink($file);
                }
            }
        }

        // Staging directories left behind by a build that died before
        // close() (AccountExportBuilder cleans them on the happy + error
        // paths, so a survivor means a hard kill).
        $dirs = glob($this->exportDir . '/account-export-*.parts', GLOB_ONLYDIR);
        if (false !== $dirs) {
            foreach ($dirs as $dir) {
                $mtime = filemtime($dir);
                if (is_int($mtime) && $mtime <= $cutoff->getTimestamp()) {
                    $this->removeDir($dir);
                }
            }
        }
    }

    private function removeDir(string $dir): void
    {
        $entries = glob($dir . '/*');
        if (false !== $entries) {
            foreach ($entries as $entry) {
                if (is_file($entry)) {
                    unlink($entry);
                }
            }
        }
        rmdir($dir);
    }
}
