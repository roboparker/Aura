<?php

namespace App\MessageHandler;

use App\Entity\SpaceExport;
use App\Message\GenerateSpaceExport;
use App\Service\SpaceExportBuilder;
use App\Service\SpaceExportMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker side of `POST /spaces/{id}/export`: builds the zip, stamps the
 * (hashed) download token + expiry on the SpaceExport row, and emails the
 * requester the link.
 *
 * Failure semantics: a build error marks the row `failed` and rethrows, so
 * Messenger's retry strategy re-runs it (the handler flips the row back to
 * `processing` on each attempt — only `completed` short-circuits). A row
 * whose retries are exhausted stays `failed` until the nightly prune reaps
 * it past the retention window.
 */
#[AsMessageHandler]
final class GenerateSpaceExportHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpaceExportBuilder $builder,
        private SpaceExportMailer $mailer,
        private LoggerInterface $logger,
        #[Autowire('%app.space_export_retention_days%')]
        private int $retentionDays,
    ) {
    }

    public function __invoke(GenerateSpaceExport $message): void
    {
        $export = $this->em->find(SpaceExport::class, $message->exportId);
        if (null === $export) {
            // Row already pruned or its space deleted — nothing to build.
            return;
        }
        if (SpaceExport::STATUS_COMPLETED === $export->getStatus()) {
            // Idempotency guard for a redelivered message.
            return;
        }

        $export->setStatus(SpaceExport::STATUS_PROCESSING);
        $this->em->flush();

        try {
            $file = $this->builder->build($export);
        } catch (\Throwable $e) {
            $export->setStatus(SpaceExport::STATUS_FAILED);
            $this->em->flush();
            throw $e;
        }

        $size = filesize($file);
        $plainToken = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable();

        $export->setFilePath($file);
        $export->setFileSize(is_int($size) ? $size : null);
        $export->setTokenHash(hash('sha256', $plainToken));
        $export->setCompletedAt($now);
        $export->setExpiresAt($now->add(new \DateInterval(sprintf('P%dD', $this->retentionDays))));
        $export->setStatus(SpaceExport::STATUS_COMPLETED);

        // One export per space: now that this one is ready it supersedes
        // any older export of the same space (a previous completed archive
        // still inside its 7-day window, or a leftover failed row). Remove
        // those rows in the same flush as the completion so the invariant
        // holds atomically, then delete their files once the DB has
        // committed. The concurrent-request 409 already prevents two builds
        // racing, so "older" here only means already-finished rows.
        $supersededPaths = $this->removeSupersededRows($export);
        $this->em->flush();
        foreach ($supersededPaths as $oldPath) {
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }

        $this->mailer->sendExportReady($export, $plainToken);

        $this->logger->info(sprintf(
            'Space export %s for space %s completed (%s).',
            (string) $export->getId(),
            (string) $export->getSpace()->getId(),
            $file,
        ));
    }

    /**
     * Marks every other export of this export's space for removal and
     * returns their on-disk paths (so the caller can unlink the files
     * after the row deletion has committed).
     *
     * @return list<string>
     */
    private function removeSupersededRows(SpaceExport $keep): array
    {
        $others = $this->em->getRepository(SpaceExport::class)
            ->createQueryBuilder('e')
            ->where('e.space = :space')
            ->andWhere('e.id != :id')
            ->setParameter('space', $keep->getSpace())
            ->setParameter('id', $keep->getId())
            ->getQuery()
            ->getResult();

        $paths = [];
        /** @var SpaceExport $old */
        foreach ($others as $old) {
            $path = $old->getFilePath();
            if (null !== $path) {
                $paths[] = $path;
            }
            $this->em->remove($old);
        }

        return $paths;
    }
}
