<?php

namespace App\MessageHandler;

use App\Message\SyncOrganizationSeats;
use App\Service\OrganizationSeatSync;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the seat push when a membership change enqueues
 * {@see SyncOrganizationSeats}. Idempotent: the gateway skips the write when
 * Stripe already holds the right quantity, so a retry or a double-dispatch
 * can't generate a duplicate proration line.
 */
#[AsMessageHandler]
final class SyncOrganizationSeatsHandler
{
    public function __construct(private OrganizationSeatSync $seatSync)
    {
    }

    public function __invoke(SyncOrganizationSeats $message): void
    {
        // Let a BillingException escape: Messenger's retry policy is the right
        // place to handle a transient Stripe outage, and a seat push that
        // never lands should end up in the failure transport where it's
        // visible, not be swallowed here.
        $this->seatSync->sync($message->organizationId);
    }
}
