<?php

namespace App\Message;

/**
 * Push an organization's billable seat count to Stripe (#billing Phase 1c),
 * dispatched whenever its membership changes. Carries the org id as a string
 * rather than the entity so the payload survives serialization to the Doctrine
 * transport and the handler always reads fresh state — by the time the worker
 * runs, the roster may have changed again.
 */
final class SyncOrganizationSeats
{
    public function __construct(public readonly string $organizationId)
    {
    }
}
