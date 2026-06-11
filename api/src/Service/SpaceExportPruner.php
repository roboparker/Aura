<?php

namespace App\Service;

use App\Entity\SpaceExport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes space exports past their retention window: the zip on disk and
 * the space_export row. Runs nightly via the scheduler
 * (App\Message\PruneSpaceExports) and on demand through
 * `bin/console app:space-exports:prune`.
 *
 * Two reaping rules:
 *  - completed exports whose `expiresAt` has passed (the advertised
 *    "link valid for N days" lifetime), and
 *  - rows that never completed (pending/processing/failed with no expiry)
 *    once they age past the same retention window — covers exports whose
 *    job exhausted its retries or whose worker died mid-build.
 *
 * A final filesystem sweep removes orphaned archives (e.g. the row was
 * CASCADE-deleted with its space, or a crashed build left a `.tmp`)
 * whose mtime is older than the retention window: every live export's
 * file is younger than that by construction.
 */
final class SpaceExportPruner
{
    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%app.space_export_dir%')]
        private string $exportDir,
        #[Autowire('%app.space_export_retention_days%')]
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

        $expired = $this->em->getRepository(SpaceExport::class)
            ->createQueryBuilder('e')
            ->where('e.expiresAt <= :now OR (e.expiresAt IS NULL AND e.createdAt <= :cutoff)')
            ->setParameter('now', $now)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        /** @var SpaceExport $export */
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
        foreach (['/space-export-*.zip', '/space-export-*.zip.tmp'] as $pattern) {
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
    }
}
