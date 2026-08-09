<?php

namespace App\MessageHandler;

use App\Message\PurgeDeletedOrganizations;
use App\Service\OrganizationDeletionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the organization purge pass when the scheduler fires
 * {@see PurgeDeletedOrganizations} (nightly). Idempotent — a double-fire finds
 * nothing still due, and a single org that fails is logged and left due rather
 * than stalling the ones behind it.
 */
#[AsMessageHandler]
final class PurgeDeletedOrganizationsHandler
{
    public function __construct(
        private OrganizationDeletionService $deletion,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeDeletedOrganizations $message): void
    {
        $purged = $this->deletion->purgeDue();

        if ($purged > 0) {
            $this->logger->info(sprintf('Organization purge pass: hard-deleted %d organization(s).', $purged));
        }
    }
}
