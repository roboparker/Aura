<?php

namespace App\MessageHandler;

use App\Entity\AccountExport;
use App\Message\GenerateAccountExport;
use App\Service\AccountExportBuilder;
use App\Service\AccountExportMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker side of `POST /me/export`: builds the zip, stamps the (hashed)
 * download token + expiry on the AccountExport row, and emails the requester
 * the link.
 *
 * Failure semantics mirror {@see GenerateSpaceExportHandler}: a build error
 * marks the row `failed` and rethrows, so Messenger's retry strategy re-runs
 * it (only `completed` short-circuits). A row whose retries are exhausted
 * stays `failed` until the nightly prune reaps it past the retention window.
 */
#[AsMessageHandler]
final class GenerateAccountExportHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccountExportBuilder $builder,
        private AccountExportMailer $mailer,
        private LoggerInterface $logger,
        #[Autowire('%app.account_export_retention_days%')]
        private int $retentionDays,
    ) {
    }

    public function __invoke(GenerateAccountExport $message): void
    {
        $export = $this->em->find(AccountExport::class, $message->exportId);
        if (null === $export) {
            // Row already pruned or the requester deleted — nothing to build.
            return;
        }
        if (AccountExport::STATUS_COMPLETED === $export->getStatus()) {
            // Idempotency guard for a redelivered message.
            return;
        }

        $export->setStatus(AccountExport::STATUS_PROCESSING);
        $this->em->flush();

        try {
            $file = $this->builder->build($export);
        } catch (\Throwable $e) {
            $export->setStatus(AccountExport::STATUS_FAILED);
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
        $export->setStatus(AccountExport::STATUS_COMPLETED);

        // One export per user: now that this one is ready it supersedes any
        // older export of the same requester. Remove those rows in the same
        // flush as the completion so the invariant holds atomically, then
        // delete their files once the DB has committed.
        $supersededPaths = $this->removeSupersededRows($export);
        $this->em->flush();
        foreach ($supersededPaths as $oldPath) {
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }

        $this->mailer->sendExportReady($export, $plainToken);

        $this->logger->info(sprintf(
            'Account export %s for user %s completed (%s).',
            (string) $export->getId(),
            (string) $export->getRequestedBy()->getId(),
            $file,
        ));
    }

    /**
     * Marks every other export of this export's requester for removal and
     * returns their on-disk paths (so the caller can unlink the files after
     * the row deletion has committed).
     *
     * @return list<string>
     */
    private function removeSupersededRows(AccountExport $keep): array
    {
        $others = $this->em->getRepository(AccountExport::class)
            ->createQueryBuilder('e')
            ->where('e.requestedBy = :user')
            ->andWhere('e.id != :id')
            ->setParameter('user', $keep->getRequestedBy())
            ->setParameter('id', $keep->getId())
            ->getQuery()
            ->getResult();

        $paths = [];
        /** @var AccountExport $old */
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
