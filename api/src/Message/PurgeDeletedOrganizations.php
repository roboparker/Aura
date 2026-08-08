<?php

namespace App\Message;

/**
 * Scheduler message: hard-delete organizations whose post-deletion grace period
 * has lapsed (see App\Service\OrganizationDeletionService). Attached to the
 * default schedule nightly by App\Scheduler\MainScheduleProvider; carries no
 * payload because the service derives everything from the stored `purgeAfter`.
 */
final class PurgeDeletedOrganizations
{
}
