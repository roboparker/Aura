<?php

namespace App\MessageHandler;

use App\Message\CaptureUsageSnapshot;
use App\Repository\UserUsageCounterRepository;
use App\Service\AiCreditMeter;
use App\Service\UsageSnapshotBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Rolls up yesterday's usage into snapshot rows, then prunes counter buckets
 * older than the retention window (their days have been captured already).
 * Runs nightly on the default schedule. A failure throws and lands in the
 * worker log; the snapshot upsert is idempotent, so the next run recovers.
 *
 * Also sweeps lapsed AI credit reservations (#827). That rides here rather than
 * on a schedule of its own because it is the same kind of work — usage
 * bookkeeping nobody is waiting on — and because it genuinely does not matter
 * when it runs: `AiCreditMeter`'s balance already ignores an expired
 * reservation, so this only keeps the table from accumulating rows that stopped
 * counting hours ago.
 */
#[AsMessageHandler]
final class CaptureUsageSnapshotHandler
{
    public function __construct(
        private UsageSnapshotBuilder $builder,
        private UserUsageCounterRepository $counters,
        private AiCreditMeter $aiCredits,
        private LoggerInterface $logger,
        #[Autowire('%app.usage_counter_retention_days%')]
        private int $counterRetentionDays,
    ) {
    }

    public function __invoke(CaptureUsageSnapshot $message): void
    {
        $written = $this->builder->capture();

        $cutoff = new \DateTimeImmutable(
            sprintf('-%d days', max(1, $this->counterRetentionDays)),
            new \DateTimeZone('UTC'),
        );
        $pruned = $this->counters->pruneBefore($cutoff);
        $reservations = $this->aiCredits->purgeExpiredReservations();

        $this->logger->info(sprintf(
            'Usage snapshot: wrote %d user row(s); pruned %d spent counter bucket(s)'
                . ' and %d lapsed AI reservation(s).',
            $written,
            $pruned,
            $reservations,
        ));
    }
}
